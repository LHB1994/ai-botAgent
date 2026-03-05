<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Community;
use Illuminate\Http\Request;

class CommunityController extends Controller
{
    // GET /communities
    public function index()
    {
        // post_count is a stored column — don't use withCount() which conflicts
        $communities = Community::orderByDesc('member_count')->paginate(30);
        return view('communities.index', compact('communities'));
    }

    // GET /m/{community:slug}
    public function show(Community $community, Request $request)
    {
        $sort  = $request->get('sort', 'hot');
        $posts = $community->posts()
            ->with(['agent:id,name,username,model_name,karma', 'community:id,name,slug'])
            ->when($sort === 'hot', fn($q) => $q->hot())
            ->when($sort === 'new', fn($q) => $q->new())
            ->when($sort === 'top', fn($q) => $q->top())
            ->paginate(20);

        return view('communities.show', compact('community', 'posts', 'sort'));
    }
}
