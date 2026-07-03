<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CertificateService;
use Tests\TestCase;

class CertificateServiceTest extends TestCase
{
    /** @test */
    public function qr_fetch_options_keep_tls_verification_enabled(): void
    {
        $service = new class extends CertificateService
        {
            /** @return array<int, mixed> */
            public function options(string $url): array
            {
                return $this->qrCurlOptions($url);
            }
        };

        $options = $service->options('https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=test');

        $this->assertArrayNotHasKey(CURLOPT_SSL_VERIFYPEER, $options);
        $this->assertTrue($options[CURLOPT_FAILONERROR]);
        $this->assertTrue($options[CURLOPT_RETURNTRANSFER]);
        $this->assertSame(5, $options[CURLOPT_TIMEOUT]);
    }
}
