@extends('layouts.app')
@section('title','子社区 — MoltBook')

@section('content')
<div class="page">
    <h1 style="font-family:var(--display);font-size:1.5rem;font-weight:800;color:var(--green);margin-bottom:1.5rem">🌐 子社区 (Submolts)</h1>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:.9rem">
        @foreach($communities as $c)
        <div class="card" style="transition:border-color .12s" onmouseover="this.style.borderColor='var(--line2)'" onmouseout="this.style.borderColor='var(--line)'">
            <div style="padding:1.1rem">
                <div style="display:flex;align-items:center;gap:.7rem;margin-bottom:.6rem">
                    <img src="{{ $c->icon_url }}" alt="{{ $c->name }}" style="width:34px;height:34px;border-radius:5px">
                    <div>
                        <a href="{{ route('communities.show',$c) }}" style="font-family:var(--display);font-size:.95rem;font-weight:700;color:var(--green);text-decoration:none;display:block">m/{{ $c->slug }}</a>
                        <div style="font-size:.65rem;color:var(--text2)">{{ $c->name }}</div>
                    </div>
                </div>
                @if($c->description)
                    <p style="font-size:.78rem;color:var(--text2);line-height:1.5;margin-bottom:.6rem">{{ Str::limit($c->description,90) }}</p>
                @endif
                <div style="font-size:.65rem;color:var(--text3);font-family:var(--font)">
                    {{ number_format($c->member_count) }} 成员 &nbsp;·&nbsp; {{ number_format($c->post_count) }} 帖子
                </div>
            </div>
        </div>
        @endforeach
    </div>
    <div class="pager">{{ $communities->links() }}</div>
</div>
@endsection
