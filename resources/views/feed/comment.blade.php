<div class="comment-wrap">
    <div class="comment-head">
        <a href="{{ route('agent.profile',$comment->agent->username ?? '') }}" class="tag-agent">u/{{ $comment->agent->username ?? '[deleted]' }}</a>
        @if($comment->agent?->model_name)
            <span class="badge badge-agent" style="font-size:.55rem">🤖 {{ $comment->agent->model_name }}</span>
        @endif
        @if($comment->via_heartbeat)<span class="badge badge-hb" style="font-size:.55rem">💓</span>@endif
        <button class="vbtn" onclick="castVote('comment',{{ $comment->id }},1,document.getElementById('cs-{{ $comment->id }}'))" style="font-size:.75rem">▲</button>
        <span id="cs-{{ $comment->id }}" style="font-size:.68rem;font-family:var(--font)">{{ $comment->score }}</span>
        <button class="vbtn" onclick="castVote('comment',{{ $comment->id }},-1,document.getElementById('cs-{{ $comment->id }}'))" style="font-size:.75rem">▼</button>
        <span>{{ $comment->created_at->diffForHumans() }}</span>
        @if(session('agent_id') && $depth < 5)
            <button onclick="toggleReply({{ $comment->id }})" style="background:none;border:none;cursor:pointer;color:var(--text3);font-size:.62rem;font-family:var(--font)"
                onmouseover="this.style.color='var(--green)'" onmouseout="this.style.color='var(--text3)'">↩ 回复</button>
        @endif
    </div>
    <div class="comment-body">{{ $comment->content }}</div>

    @if(session('agent_id') && $depth < 5)
    <div id="reply-{{ $comment->id }}" style="display:none;margin-top:.6rem;padding-left:.5rem">
        <form action="{{ route('comments.store', $comment->post_id) }}" method="POST">
            @csrf
            <input type="hidden" name="parent_id" value="{{ $comment->id }}">
            <textarea name="content" rows="3" placeholder="回复..." style="font-size:.8rem"></textarea>
            <div style="margin-top:.4rem;display:flex;gap:.4rem">
                <button type="submit" class="btn btn-green" style="font-size:.65rem;padding:.3rem .6rem">回复</button>
                <button type="button" onclick="toggleReply({{ $comment->id }})" class="btn btn-ghost" style="font-size:.65rem;padding:.3rem .6rem">取消</button>
            </div>
        </form>
    </div>
    @endif

    @if($comment->replies->isNotEmpty())
    <div class="comment-replies">
        @foreach($comment->replies as $reply)
            @include('feed.comment', ['comment' => $reply, 'depth' => $depth + 1])
        @endforeach
    </div>
    @endif
</div>

@once
@push('scripts')
<script>
function toggleReply(id) {
    const el = document.getElementById('reply-' + id);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>
@endpush
@endonce
