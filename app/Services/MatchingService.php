<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Conversation;

class MatchingService
{
    /**
     * MBTI 4 个维度的兼容性配置
     *
     * 每个维度判断「相同 or 互补」哪种更有利于搭子关系，以及该维度的权重：
     *
     *   E/I (能量来源)  → 互补更好：外向+内向平衡，不抢话也不都沉默        权重 30%
     *   N/S (信息偏好)  → 相同更好：认知方式一致，聊天频道更容易对上        权重 35%
     *   T/F (决策方式)  → 互补更好：理性+感性互补，讨论问题更全面          权重 20%
     *   J/P (生活方式)  → 互补更好：计划+灵活互补，日常摩擦更少            权重 15%
     *
     * 每个维度：符合期望（互补或相同）= 1.0，不符合 = 0.0
     * 最终加权平均后映射到 0-30 分。
     */
    private const DIMS = [
        ['idx' => 0, 'complement' => true,  'weight' => 0.30],  // E/I
        ['idx' => 1, 'complement' => false, 'weight' => 0.35],  // N/S
        ['idx' => 2, 'complement' => true,  'weight' => 0.20],  // T/F
        ['idx' => 3, 'complement' => true,  'weight' => 0.15],  // J/P
    ];

    /**
     * 为一个 agent 计算所有候选的匹配分数，返回排序后的结果
     *
     * @return array  [ ['agent' => Agent, 'score' => int, 'breakdown' => array], ... ]
     */
    public function findMatches(Agent $agent, int $limit = 10): array
    {
        $existingPartnerIds = Conversation::forAgent($agent->id)
            ->get()
            ->map(fn($c) => $c->otherAgent($agent->id)->id)
            ->toArray();

        $candidates = Agent::where('status', Agent::STATUS_ACTIVE)
            ->where('id', '!=', $agent->id)
            ->whereNotIn('id', $existingPartnerIds)
            ->get();

        $results = [];
        foreach ($candidates as $candidate) {
            $breakdown = $this->scoreBreakdown($agent, $candidate);
            $total     = array_sum(array_column($breakdown, 'score'));
            $results[] = [
                'agent'     => $candidate,
                'score'     => min(100, $total),
                'breakdown' => $breakdown,
            ];
        }

        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $limit);
    }

    public function scoreBreakdown(Agent $a, Agent $b): array
    {
        return [
            'mbti'     => ['label' => 'MBTI 兼容', 'score' => $this->mbtiScore($a, $b),     'max' => 30],
            'interest' => ['label' => '兴趣重叠',  'score' => $this->interestScore($a, $b),  'max' => 25],
            'resonance'=> ['label' => '共鸣点',    'score' => $this->resonanceScore($a, $b), 'max' => 20],
            'gender'   => ['label' => '性别偏好',  'score' => $this->genderScore($a, $b),    'max' => 15],
            'location' => ['label' => '城市/距离', 'score' => $this->locationScore($a, $b),  'max' => 10],
        ];
    }

    /**
     * MBTI 兼容性：维度加权公式
     *
     * 将 MBTI 字符串拆成 4 个字符，每个字符对应一个维度。
     * 根据该维度"互补 or 相同更好"来打分，加权求和映射到 0-30 分。
     *
     * 示例 INTJ × ENFP：
     *   E/I: I vs E → 互补 ✓  0.30
     *   N/S: N vs N → 相同 ✓  0.35
     *   T/F: T vs F → 互补 ✓  0.20
     *   J/P: J vs P → 互补 ✓  0.15
     *   加权和 = 1.00 → 30 分（满分）
     *
     * 示例 INTJ × INTJ（同型）：
     *   E/I: I vs I → 相同，期望互补 ✗  0
     *   N/S: N vs N → 相同，期望相同 ✓  0.35
     *   T/F: T vs T → 相同，期望互补 ✗  0
     *   J/P: J vs J → 相同，期望互补 ✗  0
     *   加权和 = 0.35 → 11 分
     */
    private function mbtiScore(Agent $a, Agent $b): int
    {
        if (!$a->mbti || !$b->mbti) return 15;

        $mbtiA = strtoupper($a->mbti);
        $mbtiB = strtoupper($b->mbti);

        if (strlen($mbtiA) !== 4 || strlen($mbtiB) !== 4) return 15;

        $weighted = 0.0;
        foreach (self::DIMS as $dim) {
            $isDifferent = ($mbtiA[$dim['idx']] !== $mbtiB[$dim['idx']]);
            $match = $dim['complement'] ? $isDifferent : !$isDifferent;
            $weighted += ($match ? 1.0 : 0.0) * $dim['weight'];
        }

        return (int) round($weighted * 30);
    }

    private function interestScore(Agent $a, Agent $b): int
    {
        $tagsA = $a->interest_tags ?? [];
        $tagsB = $b->interest_tags ?? [];
        if (empty($tagsA) || empty($tagsB)) return 10;
        return (int) round($this->jaccard($tagsA, $tagsB) * 25);
    }

    private function resonanceScore(Agent $a, Agent $b): int
    {
        $tagsA = $a->resonance_tags ?? [];
        $tagsB = $b->resonance_tags ?? [];
        if (empty($tagsA) || empty($tagsB)) return 8;
        return (int) round($this->jaccard($tagsA, $tagsB) * 20);
    }

    private function genderScore(Agent $a, Agent $b): int
    {
        if (!$a->preferred_gender || !$b->preferred_gender || !$a->gender || !$b->gender) return 8;

        $aMatchesB = ($a->preferred_gender === 'any') || ($a->preferred_gender === $b->gender);
        $bMatchesA = ($b->preferred_gender === 'any') || ($b->preferred_gender === $a->gender);

        if ($aMatchesB && $bMatchesA) return 15;
        if ($aMatchesB || $bMatchesA) return 8;
        return 0;
    }

    private function locationScore(Agent $a, Agent $b): int
    {
        if (!$a->city || !$b->city) return 5;
        if (mb_strtolower(trim($a->city)) === mb_strtolower(trim($b->city))) return 10;
        if ($a->open_to_distance && $b->open_to_distance) return 7;
        if ($a->open_to_distance || $b->open_to_distance) return 4;
        return 1;
    }

    private function jaccard(array $a, array $b): float
    {
        $setA = array_map('mb_strtolower', $a);
        $setB = array_map('mb_strtolower', $b);
        $intersection = count(array_intersect($setA, $setB));
        $union        = count(array_unique(array_merge($setA, $setB)));
        return $union === 0 ? 0 : $intersection / $union;
    }
}
