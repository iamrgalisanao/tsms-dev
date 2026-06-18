<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exception\HttpResponseException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register()
    {
        // Keep the default reporting behavior
        $this->reportable(function (Throwable $e) {
            // let the framework handle logging by default
        });
    }

    public function render($request, Throwable $e)
    {
        // If the request expects JSON, let the normal JSON response flow continue
        if ($request->expectsJson()) {
            return parent::render($request, $e);
        }

        // If the user is not authorized (AuthorizationException or HTTP 403),
        // redirect them to their role-specific index/home page instead of showing 403.
        if ($e instanceof AuthorizationException
            || ($e instanceof HttpException && $e->getStatusCode() === 403)) {

            try {
                $user = Auth::user();
                if ($user) {
                    $home = $this->roleHomeUrl($user);
                    // Log for audit
                    Log::info('Redirecting unauthorized user to role home', [
                        'user_id' => $user->id,
                        'email' => $user->email ?? null,
                        'target' => $home,
                        'path' => $request->path(),
                    ]);

                    return redirect($home)->with('error', 'You do not have access to the requested page. Redirected to your home.');
                }
            } catch (\Exception $inner) {
                // If something goes wrong, fall back to the default render
                Log::warning('Error while handling AuthorizationException redirect: ' . $inner->getMessage());
                return parent::render($request, $e);
            }
        }

        return parent::render($request, $e);
    }

    /**
     * Map user roles to their respective home/index URL.
     */
    protected function roleHomeUrl($user): string
    {
        // Prefer spatie/laravel-permission style hasRole
        if (method_exists($user, 'hasRole')) {
            if ($user->hasRole('admin')) {
                return url('/dashboard');
            }
            if ($user->hasRole('commercial')) {
                return url('/commercial');
            }
            if ($user->hasRole('finance')) {
                return route('reports.index');
            }
            if ($user->hasRole('manager')) {
                return url('/transactions/logs');
            }
        }

        // Fallback: if there's a role attribute, try to map it
        if (isset($user->role)) {
            $role = is_object($user->role) ? strtolower($user->role->name) : strtolower($user->role);
            switch ($role) {
                case 'admin':
                    return url('/dashboard');
                case 'commercial':
                    return url('/commercial');
                case 'finance':
                    return route('reports.index');
                case 'manager':
                    return url('/transactions/logs');
            }
        }

        // Default home
        return url('/dashboard');
    }
}
