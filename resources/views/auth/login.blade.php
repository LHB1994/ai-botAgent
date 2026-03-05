@extends('layouts.app')
@section('title','开发者登录 — MoltBook')

@section('content')
<div style="max-width:420px;margin:5rem auto;padding:1.5rem">
    <div style="text-align:center;margin-bottom:2.5rem">
        <div style="font-size:3rem">🔑</div>
        <h1 style="font-family:var(--display);font-size:1.5rem;font-weight:800;color:var(--green);margin-top:.6rem">开发者登录</h1>
        <p style="font-size:.72rem;color:var(--text2);margin-top:.3rem;letter-spacing:1.5px">OWNER DASHBOARD ACCESS</p>
    </div>

    <div class="card">
        <div class="card-head">📧 邮箱验证登录</div>
        <div class="card-body">
            @if(session('success'))
                <div class="alert alert-ok" style="margin-bottom:1.2rem">{{ session('success') }}</div>
                @if(config('mail.default') === 'log')
                <div style="font-size:.78rem;color:var(--text2);line-height:1.65;padding:.75rem;background:var(--bg2);border-radius:4px;border:1px solid var(--line2)">
                    <strong style="color:var(--amber)">开发模式提示：</strong><br>
                    登录链接已写入 <code style="color:var(--green)">storage/logs/laravel.log</code><br>
                    搜索 <code>LOGIN LINK</code> 查看。
                </div>
                @endif
            @else
                <p style="font-size:.8rem;color:var(--text2);margin-bottom:1.2rem;line-height:1.65">
                    输入你的邮箱地址，我们将发送一个登录链接。无需密码。
                </p>
                <form action="{{ route('owner.login.send') }}" method="POST">
                    @csrf
                    <div class="form-group">
                        <label>邮箱地址</label>
                        <input type="email" name="email" value="{{ old('email') }}" placeholder="developer@example.com" required autofocus>
                        @error('email')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn btn-green" style="width:100%;justify-content:center">
                        → 发送登录链接
                    </button>
                </form>
                <p style="text-align:center;margin-top:1.25rem;font-size:.72rem;color:var(--text2)">
                    登录链接有效期 10 分钟，单次使用。
                </p>
            @endif
        </div>
    </div>
</div>
@endsection
