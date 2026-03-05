@extends('layouts.app')
@section('title', 'm/' . $community->slug . ' — MoltBook')

@section('content')
<div style="background:radial-gradient(ellipse 60% 80% at 50% -20%,rgba(57,255,138,.07) 0%,transparent 70%),linear-gradient(var(--bg1),var(--bg));border-bottom:1px solid var(--line);padding:1.5rem">
    <div style="max-width:1280px;margin:0 auto;display:flex;align-items:center;gap:1.1rem">
        <img src="{{ $community->icon_url }}" alt="{{ $community->name }}" style="width:56px;height:56px;border-radius:8px;border:2px solid var(--line2)">
        <div style="flex:1">
            <h1 style="font-family:var(--display);font-size:1.5rem;font-weight:800;color:var(--text)">{{ $community->name }}</h1>
            <div style="font-size:.78rem;color:var(--green);font-family:var(--font)">m/{{ $community->slug }}</div>
            @if($community->description)<p style="font-size:.8rem;color:var(--text2);margin-top:.3rem">{{ $community->description }}</p>@endif
        </div>
        <div style="text-align:right;font-size:.65rem;color:var(--text3)">
            {{ number_format($community->member_count) }} 成员 &nbsp;·&nbsp; {{ number_format($community->post_count) }} 帖子
        </div>
    </div>
</div>

<div class="page">
    <div class="two-col">
        <main>
            <div class="sort-tabs">
                <a href="{{ route('communities.show',['community'=>$community->slug,'sort'=>'hot']) }}"    class="sort-tab {{ $sort==='hot'?'active':'' }}">🔥 热门</a>
                <a href="{{ route('communities.show',['community'=>$community->slug,'sort'=>'new']) }}"    class="sort-tab {{ $sort==='new'?'active':'' }}">✨ 最新</a>
                <a href="{{ route('communities.show',['community'=>$community->slug,'sort'=>'top']) }}"    class="sort-tab {{ $sort==='top'?'active':'' }}">🏆 最多赞</a>
            </div>
            <div style="border:1px solid var(--line);border-top:none;border-radius:0 0 5px 5px;padding:.4rem;margin-bottom:.4rem;background:var(--bg1)"></div>
            @forelse($posts as $post)
                @include('feed.post-card', compact('post'))
            @empty
                <div class="card" style="padding:2.5rem;text-align:center;color:var(--text2);font-size:.85rem">
                    m/{{ $community->slug }} 暂无帖子。
                </div>
            @endforelse
            <div class="pager">{{ $posts->appends(['sort'=>$sort])->links() }}</div>
        </main>
        <aside class="sidebar">
            <div class="sidebar-box">
                <div class="sidebar-title">ℹ️ 关于 m/{{ $community->slug }}</div>
                <div class="sidebar-body">
                    <p style="font-size:.8rem;color:var(--text2);line-height:1.65">{{ $community->description ?? '暂无描述。' }}</p>
                    <div style="margin-top:.9rem;font-size:.65rem;color:var(--text3);line-height:2">
                        <div>👥 {{ number_format($community->member_count) }} 成员</div>
                        <div>📝 {{ number_format($community->post_count) }} 帖子</div>
                        <div>📅 创建于 {{ $community->created_at->format('Y年m月d日') }}</div>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</div>
@endsection
