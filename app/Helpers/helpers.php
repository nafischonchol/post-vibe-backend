<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function responseSuccess($data, string $message = 'Success', int $code = 200, $meta = null)
{
    $items = $data;
    if (is_array($data) && isset($data['items'])) {
        $items = $data['items'];
    }

    $response = [
        'status' => 'success',
        'success' => true,
        'message' => $message,
        'resources' => $items,
        'data' => $items,
    ];

    if ($meta) {
        $response['meta'] = $meta;
        $response['pagination'] = $meta;
    } elseif (is_array($data) && isset($data['pagination'])) {
        $response['pagination'] = $data['pagination'];
        $response['meta'] = $data['pagination'];
    } elseif (is_array($data) && isset($data['meta'])) {
        $response['meta'] = $data['meta'];
        $response['pagination'] = $data['meta'];
    }

    if (is_array($data) && isset($data['summary'])) {
        $response['summary'] = $data['summary'];
    }

    return response()->json($response, $code);
}

function extractIdFromSlug($slug_url)
{
    if (is_numeric($slug_url) || strrpos($slug_url, '-') === false) {
        return (int) $slug_url;
    }

    return (int) substr($slug_url, strrpos($slug_url, '-') + 1);
}

function responseError(string $message, string|int|null $code = 500, $exception = null, $data = null)
{
    if ($exception instanceof ModelNotFoundException || $exception instanceof NotFoundHttpException) {
        $code = 404;
        $message = 'Resource not found.';
    }

    if ($exception) {
        Log::error($exception);
    }

    if (! is_int($code) || $code < 100 || $code >= 600) {
        $code = 500;
    }

    $response = [
        'status' => 'error',
        'success' => false,
        'message' => $message,
    ];

    if ($data) {
        $response['error'] = $data;
        $response['errors'] = $data;
    }

    return response()->json($response, $code);
}

function pagination($data)
{
    if (
        ! is_object($data)
        || ! method_exists($data, 'currentPage')
        || ! method_exists($data, 'lastPage')
        || ! method_exists($data, 'perPage')
        || ! method_exists($data, 'total')
    ) {
        return null;
    }

    return [
        'current_page' => $data->currentPage(),
        'last_page' => $data->lastPage(),
        'per_page' => $data->perPage(),
        'total' => $data->total(),
    ];
}
