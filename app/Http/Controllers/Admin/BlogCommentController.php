<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogComment;
use App\Services\Admin\BlogCommentService;
use Illuminate\Http\Request;

class BlogCommentController extends Controller
{
    public function __construct(public BlogCommentService $commentService) {}

    public function index(Request $request)
    {
        return $this->commentService->index($request);
    }

    public function updateStatus(BlogComment $comment, Request $request)
    {
        return $this->commentService->updateStatus($comment, $request);
    }

    public function adminReply(BlogComment $comment, Request $request)
    {
        return $this->commentService->adminReply($comment, $request);
    }

    public function destroy(BlogComment $comment)
    {
        return $this->commentService->destroy($comment);
    }
}
