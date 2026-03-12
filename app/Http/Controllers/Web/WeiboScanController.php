<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\WeiboScanService;
use Illuminate\Http\Request;

class WeiboScanController extends Controller
{
    public function __construct(private WeiboScanService $scanner) {}

    /**
     * POST /dashboard/weibo/scan/reset
     */
    public function reset(Request $request)
    {
        $owner = $request->attributes->get('owner');
        $owner->update(['weibo_scan_since_id' => 0]);
        session()->forget('weibo_scan_result');
        return back()->with('success', '游标已重置，下次扫描将重新拉取所有 @ 消息。');
    }

    /**
     * GET /dashboard/weibo/scan
     */
    public function index(Request $request)
    {
        $owner = $request->attributes->get('owner');

        // 从 session 恢复上次扫描结果（激活后跳回来时保留剩余条目）
        $result = session('weibo_scan_result');

        return view('dashboard.weibo-scan', [
            'owner'  => $owner,
            'result' => $result,
        ]);
    }

    /**
     * POST /dashboard/weibo/scan
     */
    public function scan(Request $request)
    {
        $owner  = $request->attributes->get('owner');
        $count  = (int) $request->input('count', 100);
        $result = $this->scanner->scanMentions($owner, $count);

        // 存入 session，激活后跳回来可以继续处理剩余条目
        session(['weibo_scan_result' => $result]);

        return view('dashboard.weibo-scan', [
            'owner'  => $owner,
            'result' => $result,
        ]);
    }

    /**
     * POST /dashboard/weibo/activate/{agentId}
     */
    public function activate(Request $request, int $agentId)
    {
        $owner      = $request->attributes->get('owner');
        $weiboId    = $request->input('weibo_id');
        $weiboUrl   = $request->input('weibo_url');
        $screenName = $request->input('screen_name');
        $claimCode  = $request->input('claim_code');
        $avatar     = $request->input('avatar');

        $result = $this->scanner->activateAgent(
            $owner, $agentId, $weiboId, $weiboUrl, $screenName, $claimCode, $avatar
        );

        if (!$result['success']) {
            return back()->with('error', $result['error']);
        }

        // 从 session 的扫描结果中移除已激活的这条
        $scanResult = session('weibo_scan_result');
        if ($scanResult && isset($scanResult['matched'])) {
            $scanResult['matched'] = array_values(array_filter(
                $scanResult['matched'],
                fn($item) => ($item['agent']['id'] ?? null) !== $agentId
            ));
            session(['weibo_scan_result' => $scanResult]);
        }

        $agent = $result['agent'];
        $remaining = count($scanResult['matched'] ?? []);
        $msg = "✅ 代理 {$agent->name} 已激活！";
        if ($remaining > 0) {
            $msg .= "  还有 {$remaining} 条待处理，已为你保留扫描结果。";
        }

        return redirect()
            ->route('weibo.scan')
            ->with('success', $msg);
    }
}
