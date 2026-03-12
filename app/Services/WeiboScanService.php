<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\ActivityLog;
use App\Models\Owner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WeiboScanService
{
    private string $baseUrl = 'https://api.weibo.com/2';

    /**
     * 拉取 @mentions 列表，提取 claim_code，返回匹配结果
     * 不做激活，只返回数据供管理员确认
     */
    public function scanMentions(Owner $owner, int $count = 100): array
    {
        if (!$owner->hasWeiboToken()) {
            return ['success' => false, 'error' => '微博 Token 无效或已过期'];
        }

        // 用 since_id 避免重复扫描已处理过的微博
        $sinceId = $owner->weibo_scan_since_id ?? 0;

        $params = [
            'access_token' => $owner->weibo_access_token,
            'count'        => min($count, 200),
            'filter_by_type' => 0, // 全部微博
        ];

        if ($sinceId > 0) {
            $params['since_id'] = $sinceId;
        }

        try {
            $response = Http::timeout(15)
                ->get("{$this->baseUrl}/statuses/mentions.json", $params);

            if (!$response->successful()) {
                $error = $response->json('error') ?? $response->body();
                Log::error('WeiboScan: API error', ['status' => $response->status(), 'error' => $error]);
                return ['success' => false, 'error' => "微博接口错误：{$error}"];
            }

            $data     = $response->json();
            $statuses = $data['statuses'] ?? [];
            $total    = $data['total_number'] ?? 0;

        } catch (\Exception $e) {
            Log::error('WeiboScan: request failed', ['msg' => $e->getMessage()]);
            return ['success' => false, 'error' => '网络请求失败：' . $e->getMessage()];
        }

        if (empty($statuses)) {
            return [
                'success'  => true,
                'total'    => $total,
                'statuses' => [],
                'matched'  => [],
                'unmatched'=> [],
            ];
        }

        // 获取所有待验证代理的 claim_code
        $pendingAgents = Agent::where('status', 'claimed')
            ->whereNotNull('claim_code')
            ->get()
            ->keyBy('claim_code'); // ['claim_code' => Agent]

        // 已验证过的微博 ID（避免重复展示）
        $verifiedWeiboIds = ActivityLog::where('action', 'weibo_verified')
            ->pluck('meta')
            ->map(fn($m) => is_array($m) ? ($m['weibo_id'] ?? null) : null)
            ->filter()
            ->flip()
            ->toArray();

        $matched   = [];
        $unmatched = [];
        $latestId  = 0;

        foreach ($statuses as $status) {
            $weiboId   = (string) ($status['id'] ?? '');
            $text      = $status['text'] ?? '';
            $createdAt = $status['created_at'] ?? '';
            $user      = $status['user'] ?? [];
            $uid       = (string) ($user['id'] ?? '');
            $screenName = $user['screen_name'] ?? '未知用户';
            $avatar    = $user['profile_image_url'] ?? '';
            $weiboUrl  = "https://weibo.com/{$uid}/{$weiboId}";

            // 追踪最新 ID，下次扫描用
            if ((int)$weiboId > $latestId) {
                $latestId = (int)$weiboId;
            }

            // 提取 claim_code：匹配格式如 abc-XXXX（字母+连字符+字母数字）
            $claimCode = $this->extractClaimCode($text);

            $statusData = [
                'weibo_id'    => $weiboId,
                'weibo_url'   => $weiboUrl,
                'text'        => $text,
                'user_uid'    => $uid,
                'screen_name' => $screenName,
                'avatar'      => $avatar,
                'created_at'  => $createdAt,
                'claim_code'  => $claimCode,
                'already_verified' => isset($verifiedWeiboIds[$weiboId]),
            ];

            if ($claimCode && isset($pendingAgents[$claimCode])) {
                $agent = $pendingAgents[$claimCode];
                $statusData['agent'] = [
                    'id'       => $agent->id,
                    'username' => $agent->username,
                    'name'     => $agent->name,
                ];
                $matched[] = $statusData;
            } else {
                $unmatched[] = $statusData;
            }
        }

        // 更新 since_id，下次只拉新微博
        if ($latestId > 0 && $latestId > (int)$sinceId) {
            $owner->update(['weibo_scan_since_id' => $latestId]);
        }

        return [
            'success'   => true,
            'total'     => $total,
            'statuses'  => $statuses,
            'matched'   => $matched,   // 匹配到 claim_code 的
            'unmatched' => $unmatched, // 未匹配的
        ];
    }

    /**
     * 激活单个代理，记录微博作为凭证
     */
    public function activateAgent(Owner $owner, int $agentId, string $weiboId, string $weiboUrl, string $screenName, string $claimCode, ?string $avatar = null): array
    {
        $agent = Agent::where('id', $agentId)
            ->where('status', 'claimed')
            ->first();

        if (!$agent) {
            // 给出更明确的错误原因
            $agentAny = Agent::find($agentId);
            if (!$agentAny) {
                return ['success' => false, 'error' => '代理不存在（ID: ' . $agentId . '）'];
            }
            return ['success' => false, 'error' => '代理当前状态为「' . $agentAny->status . '」，只有 claimed 状态才能激活'];
        }

        // 防止重复激活同一条微博
        $alreadyUsed = ActivityLog::where('action', 'weibo_verified')
            ->whereJsonContains('meta->weibo_id', $weiboId)
            ->exists();

        if ($alreadyUsed) {
            return ['success' => false, 'error' => '该微博已被用于验证'];
        }

        $agent->update([
            'status'       => Agent::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);

        // 把验证微博的用户头像存到 owner
        if ($avatar) {
            $agent->owner->update(['weibo_avatar' => $avatar]);
        }

        ActivityLog::create([
            'agent_id'    => $agent->id,
            'action'      => 'weibo_verified',
            'description' => "通过微博 @{$screenName} 验证激活",
            'meta'        => [
                'weibo_id'    => $weiboId,
                'weibo_url'   => $weiboUrl,
                'weibo_user'  => $screenName,
                'claim_code'  => $claimCode,
                'activated_by'=> $owner->email,
            ],
        ]);

        Log::info("WeiboScan: activated agent u/{$agent->username} via weibo {$weiboId}");

        return ['success' => true, 'agent' => $agent];
    }

    /**
     * 从微博文本中提取 claim_code
     * 格式：word-XXXX，如 splash-S3QD、orbit-X7KP
     */
    private function extractClaimCode(string $text): ?string
    {
        // 匹配 2-12位小写字母 + 连字符 + 2-8位大写字母数字
        if (preg_match('/\b([a-z]{2,12}-[A-Z0-9]{2,8})\b/', $text, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
