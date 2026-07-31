<?php

namespace App\Http\Middleware;

use App\Models\Course;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET/HEAD: если в URL slug курса — alias, а не канон, 301 на тот же named route
 * с courses.slug. POST не трогаем (method + body ломаются на redirect).
 */
class RedirectToCanonicalCourseSlug
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $route = $request->route();
        if ($route === null || $route->getName() === null) {
            return $next($request);
        }

        $requestedSlug = $this->requestedSlug($request);
        if ($requestedSlug === null) {
            return $next($request);
        }

        $course = Course::resolveBySlug($requestedSlug);
        if ($course === null || $requestedSlug === $course->slug) {
            return $next($request);
        }

        $parameters = [];
        foreach ($route->parameterNames() as $name) {
            $value = $route->parameter($name);
            if ($name === 'course' || $name === 'slug') {
                $parameters[$name] = $course->slug;

                continue;
            }
            if ($value instanceof Course) {
                $parameters[$name] = $course->slug;

                continue;
            }
            $parameters[$name] = $value;
        }

        $target = route($route->getName(), $parameters, absolute: false);
        if ($query = $request->getQueryString()) {
            $target .= (str_contains($target, '?') ? '&' : '?').$query;
        }

        return redirect()->to($target, 301);
    }

    private function requestedSlug(Request $request): ?string
    {
        $slug = $request->route('slug');
        if (is_string($slug) && $slug !== '') {
            return $slug;
        }

        $param = $request->route('course');
        if (is_string($param) && $param !== '') {
            return $param;
        }

        // После implicit binding параметр — модель; сырой сегмент из path.
        // /k/{slug}, /k/{slug}/preview, /c/{slug}, /c/{slug}/u/{id}, …
        $segments = $request->segments();
        if (isset($segments[0], $segments[1]) && in_array($segments[0], ['c', 'k'], true)) {
            return $segments[1];
        }

        return null;
    }
}
