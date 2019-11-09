<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\UseCases\Auth\SignOut\Handler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SignOutController extends Controller
{
    /**
     * SignOutController constructor.
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Handle a sign out request.
     *
     * @param Request $request
     * @param Handler $handler
     * @return Response|JsonResponse
     */
    public function destroy(Request $request, Handler $handler)
    {
        $handler->handle($request->user());

        return response()->json([], Response::HTTP_NO_CONTENT);
    }
}
