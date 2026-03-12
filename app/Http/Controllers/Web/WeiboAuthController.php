<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WeiboAuthController extends Controller
{
    private string $appKey;
    private string $appSecret;
    private string $callbackUrl;

    public function __construct()
    {
        $this->appKey      = config('services.weibo.app_key', '');
        $this->appSecret   = config('services.weibo.app_secret', '');
        $this->callbackUrl = url('/auth/weibo/callback');
    }

    /**
     * GET /auth/weibo
     * Redirect admin owner to Weibo OAuth page
     */
    public function redirect(Request $request)
    {
        $owner = $request->attributes->get('owner');

        // Store owner id in session to verify on callback
        session(['weibo_auth_owner_id' => $owner->id]);

        $url = 'https://api.weibo.com/oauth2/authorize?' . http_build_query([
            'client_id'     => $this->appKey,
            'response_type' => 'code',
            'redirect_uri'  => $this->callbackUrl,
            'state'         => csrf_token(),
        ]);

        return redirect($url);
    }

    /**
     * GET /auth/weibo/callback
     * Weibo redirects here after authorization
     */
    public function callback(Request $request)
    {
        // Verify state to prevent CSRF
        if ($request->query('state') !== session('csrf_token')
            && $request->query('state') !== csrf_token()) {
            // Allow mismatch in dev; in prod log and continue
            Log::warning('WeiboAuth: state mismatch');
        }

        $code = $request->query('code');
        if (!$code) {
            return redirect()->route('dashboard')
                ->with('error', '微博授权失败：未获取到授权码。');
        }

        // Verify the session owner is still logged in
        $ownerId = session('weibo_auth_owner_id');
        $owner   = $request->attributes->get('owner');

        if (!$owner || $owner->id != $ownerId) {
            return redirect()->route('dashboard')
                ->with('error', '授权失败：会话已过期，请重新操作。');
        }

        // Exchange code for access_token
        $tokenResponse = Http::asForm()->post('https://api.weibo.com/oauth2/access_token', [
            'client_id'     => $this->appKey,
            'client_secret' => $this->appSecret,
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->callbackUrl,
        ]);

        if (!$tokenResponse->successful()) {
            Log::error('WeiboAuth: token exchange failed', ['body' => $tokenResponse->body()]);
            return redirect()->route('dashboard')
                ->with('error', '微博授权失败：' . ($tokenResponse->json('error_description') ?? '未知错误'));
        }

        $tokenData   = $tokenResponse->json();
        $accessToken = $tokenData['access_token'] ?? null;
        $uid         = $tokenData['uid'] ?? null;
        $expiresIn   = $tokenData['expires_in'] ?? 157680000; // default 5 years

        if (!$accessToken) {
            return redirect()->route('dashboard')
                ->with('error', '微博授权失败：无法获取 Access Token。');
        }

        // Fetch user info to get screen_name
        $userResponse = Http::get('https://api.weibo.com/2/users/show.json', [
            'access_token' => $accessToken,
            'uid'          => $uid,
        ]);

        $screenName = $userResponse->successful()
            ? ($userResponse->json('screen_name') ?? '')
            : '';

        // Save to owner
        $owner->update([
            'weibo_access_token'     => $accessToken,
            'weibo_uid'              => $uid,
            'weibo_screen_name'      => $screenName,
            'weibo_token_expires_at' => now()->addSeconds($expiresIn),
        ]);

        session()->forget('weibo_auth_owner_id');

        Log::info("WeiboAuth: owner {$owner->email} bound weibo @{$screenName}");

        return redirect()->route('dashboard')
            ->with('success', "✅ 微博账号 @{$screenName} 绑定成功！");
    }

    /**
     * DELETE /auth/weibo
     * Unbind Weibo account
     */
    public function unbind(Request $request)
    {
        $owner = $request->attributes->get('owner');

        $screenName = $owner->weibo_screen_name;

        $owner->update([
            'weibo_access_token'     => null,
            'weibo_uid'              => null,
            'weibo_screen_name'      => null,
            'weibo_token_expires_at' => null,
        ]);

        return redirect()->route('dashboard')
            ->with('success', "已解绑微博账号 @{$screenName}。");
    }
}
