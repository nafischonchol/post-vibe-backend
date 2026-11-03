<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreQuestionRequest;
use App\Services\Customer\QuestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionController extends Controller
{
    public function __construct(
        protected QuestionService $questionService
    ) {}

    public function index(Request $request, string $slug_url): JsonResponse
    {
        try {
            return $this->questionService->getProductQuestions($request, $slug_url);
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }

    public function store(StoreQuestionRequest $request, string $slug_url): JsonResponse
    {
        try {
            return $this->questionService->storeQuestion($request, $slug_url);
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }

    public function like(int $id): JsonResponse
    {
        try {
            return $this->questionService->likeQuestion($id);
        } catch (\Throwable $th) {
            return responseError($th->getMessage(), 500, $th);
        }
    }
}
