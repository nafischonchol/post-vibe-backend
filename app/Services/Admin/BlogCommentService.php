<?php

namespace App\Services\Admin;

use App\Http\Resources\Blog\BlogCommentResource;
use App\Models\BlogComment;
use Illuminate\Http\Request;

class BlogCommentService
{
    public function index(Request $request)
    {
        try {
            $perPage = (int) $request->input('per_page', 20);
            $query = BlogComment::with(['post', 'client', 'replies']);

            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->filled('post_id')) {
                $query->where('blog_post_id', $request->input('post_id'));
            }

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('author_name', 'like', "%{$search}%")
                        ->orWhere('author_email', 'like', "%{$search}%")
                        ->orWhere('content', 'like', "%{$search}%");
                });
            }

            $comments = $query->latest('id')->paginate($perPage);

            return responseSuccess([
                'items' => BlogCommentResource::collection($comments),
                'pagination' => pagination($comments),
            ]);
        } catch (\Throwable $th) {
            return responseError('Failed to fetch blog comments: '.$th->getMessage(), 500, $th);
        }
    }

    public function updateStatus(BlogComment $comment, Request $request)
    {
        try {
            $request->validate([
                'status' => 'required|in:pending,approved,rejected,spam',
            ]);

            $comment->update(['status' => $request->input('status')]);

            return responseSuccess(BlogCommentResource::make($comment), 'Comment status updated successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to update comment status: '.$th->getMessage(), 500, $th);
        }
    }

    public function adminReply(BlogComment $comment, Request $request)
    {
        try {
            $request->validate([
                'content' => 'required|string|max:2000',
            ]);

            $admin = auth('sanctum')->user() ?? \App\Models\Admin::first();
            $authorName = $admin?->name ?? 'Admin';

            $reply = BlogComment::create([
                'blog_post_id' => $comment->blog_post_id,
                'parent_id' => $comment->id,
                'author_name' => $authorName . ' (Admin)',
                'author_email' => $admin?->email ?? null,
                'content' => $request->input('content'),
                'status' => 'approved',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return responseSuccess(BlogCommentResource::make($reply), 'Admin reply submitted successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to submit admin reply: '.$th->getMessage(), 500, $th);
        }
    }

    public function destroy(BlogComment $comment)
    {
        try {
            $comment->delete();

            return responseSuccess(null, 'Comment deleted successfully');
        } catch (\Throwable $th) {
            return responseError('Failed to delete comment: '.$th->getMessage(), 500, $th);
        }
    }
}
