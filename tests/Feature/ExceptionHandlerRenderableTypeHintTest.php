<?php

namespace Tests\Feature;

use App\Exceptions\Handler as AppHandler;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler as BaseHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Tests\TestCase;

class ExceptionHandlerRenderableTypeHintTest extends TestCase
{
    /**
     * Handler::render() runs prepareException() BEFORE renderViaCallbacks()
     * (Laravel Foundation/Exceptions/Handler.php) — any exception this list
     * converts never reaches a renderable() callback typed on its original
     * class. That callback is dead code, silently, forever (FINDINGS §228).
     */
    public function test_no_renderable_callback_is_type_hinted_on_a_prepare_exception_converted_class(): void
    {
        $bannedTypes = $this->conversionSourceTypes();

        $this->assertNotEmpty(
            $bannedTypes,
            'Could not derive prepareException()\'s conversion list from the installed '
            .'framework — its source no longer matches the expected `$e instanceof X` shape.'
        );

        // Independent sanity check on the SAME derivation: if a future Laravel
        // version adds/removes a match arm, the arm count moves even when the
        // regex above still happens to match something. A guard that silently
        // keeps "matching something" while missing new arms is exactly the
        // failure mode this whole test exists to prevent.
        $armCount = $this->prepareExceptionArmCount();
        $this->assertSame(
            // 9 conversion conditions (AuthorizationException has 2) + `default => $e`.
            // Laravel 13 added the OriginMismatchException => HttpException(403) arm
            // (was 9 arms on Laravel 12) — re-derived for 13.x under H2529.
            10,
            $armCount,
            "prepareException()'s match arm count is {$armCount}, not the expected 10 — ".
            'the installed Laravel version changed this method\'s shape. Re-derive the '
            .'banned-type list for the new version before trusting this test.'
        );

        /** @var AppHandler $handler */
        $handler = $this->app->make(ExceptionHandler::class);
        $this->assertInstanceOf(AppHandler::class, $handler);

        $reflection = new ReflectionClass(BaseHandler::class);
        $property = $reflection->getProperty('renderCallbacks');
        $property->setAccessible(true);
        $callbacks = $property->getValue($handler);

        $this->assertNotEmpty(
            $callbacks,
            'Handler::register() has no renderable() callbacks — nothing for this test to check.'
        );

        foreach ($callbacks as $index => $callback) {
            $function = new ReflectionFunction($callback);
            $parameters = $function->getParameters();

            if (empty($parameters)) {
                continue;
            }

            $hintedTypes = $this->parameterClassNames($parameters[0]->getType());

            foreach ($hintedTypes as $hintedType) {
                foreach ($bannedTypes as $bannedType) {
                    $isConvertedAway = $hintedType === $bannedType
                        || is_a($hintedType, $bannedType, true);

                    $this->assertFalse(
                        $isConvertedAway,
                        "renderable() callback #{$index} is type-hinted on {$hintedType}, but ".
                        "prepareException() ALWAYS rewrites {$bannedType} into an HttpException ".
                        'wrapper before render() calls renderViaCallbacks() — this callback can '.
                        'never fire. Fix: type-hint Symfony\\Component\\HttpKernel\\Exception\\HttpException '
                        ."and check \$e->getStatusCode() + \$e->getPrevious() instanceof {$bannedType}."
                    );
                }
            }
        }
    }

    /**
     * Derive prepareException()'s conversion list from the INSTALLED framework's
     * own source rather than a hardcoded copy, so a Laravel upgrade that changes
     * the list can't silently make this test stop guarding anything.
     *
     * @return list<class-string>
     */
    private function conversionSourceTypes(): array
    {
        $body = implode('', $this->prepareExceptionLines());

        preg_match_all('/\$e\s+instanceof\s+([A-Za-z0-9_\\\\]+)/', $body, $matches);
        $shortNames = array_values(array_unique($matches[1]));

        $imports = $this->parseUseImports();

        return array_map(
            static fn (string $name): string => $imports[$name] ?? $name,
            $shortNames
        );
    }

    private function prepareExceptionArmCount(): int
    {
        $body = implode('', $this->prepareExceptionLines());

        return substr_count($body, '=>');
    }

    /** @return list<string> raw source lines of BaseHandler::prepareException() */
    private function prepareExceptionLines(): array
    {
        $method = new ReflectionMethod(BaseHandler::class, 'prepareException');
        $file = $method->getFileName();

        $this->assertNotFalse($file, 'Could not locate the file defining prepareException().');

        $source = file($file);
        $this->assertNotFalse($source, "Could not read {$file}.");

        return array_slice(
            $source,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        );
    }

    /**
     * Map short class names to FQCNs using the `use ...;` statements at the top
     * of the same file that defines prepareException().
     *
     * @return array<string, class-string>
     */
    private function parseUseImports(): array
    {
        $method = new ReflectionMethod(BaseHandler::class, 'prepareException');
        $fullSource = file($method->getFileName());

        $imports = [];
        foreach ($fullSource as $line) {
            if (preg_match('/^use\s+([A-Za-z0-9_\\\\]+)\s*;/', trim($line), $m)) {
                $fqcn = ltrim($m[1], '\\');
                $short = substr($fqcn, strrpos($fqcn, '\\') !== false ? strrpos($fqcn, '\\') + 1 : 0);
                $imports[$short] = $fqcn;
            }
        }

        return $imports;
    }

    /** @return list<class-string> */
    private function parameterClassNames(?\ReflectionType $type): array
    {
        if ($type === null) {
            return [];
        }

        if ($type instanceof ReflectionUnionType) {
            return array_values(array_filter(array_map(
                fn ($t) => $t instanceof ReflectionNamedType && ! $t->isBuiltin() ? ltrim($t->getName(), '\\') : null,
                $type->getTypes()
            )));
        }

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            return [ltrim($type->getName(), '\\')];
        }

        return [];
    }

    /**
     * Regression control for the H1765 pattern this test guards against:
     * PostTooLargeException is already an HttpException — prepareException()
     * leaves it untouched — so a renderable() callback hinted on it is NOT
     * dead code and must not trip this test.
     */
    public function test_post_too_large_exception_is_not_flagged_as_converted(): void
    {
        $banned = $this->conversionSourceTypes();

        $this->assertNotContains(PostTooLargeException::class, $banned);
    }
}
