<?php

namespace Tests\Feature;

use App\Http\Controllers\V2\Admin\PluginController;
use App\Services\Plugin\PluginConfigService;
use App\Services\Plugin\PluginManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PluginUploadTest extends TestCase
{
    private MockInterface $plugins;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plugins = $this->mock(PluginManager::class);
        $this->mock(PluginConfigService::class);
        $this->withoutMiddleware();
        Route::post('/_test/plugin-upload', [PluginController::class, 'upload']);
    }

    public static function acceptedSizes(): array
    {
        return [
            '超过旧上限' => [10 * 1024 * 1024 + 1],
            '包含本地数据库的插件包' => [12 * 1024 * 1024],
            '恰好达到新上限' => [64 * 1024 * 1024],
        ];
    }

    #[DataProvider('acceptedSizes')]
    public function test_zip_up_to_64_mib_reaches_plugin_manager(int $bytes): void
    {
        $file = UploadedFile::fake()->create('plugin.zip', 1, 'application/zip');
        $file->sizeToReport = $bytes;
        $this->plugins->shouldReceive('upload')->once()
            ->with(Mockery::on(fn (UploadedFile $uploaded) => $uploaded->getSize() === $bytes))
            ->andReturnTrue();

        $this->post('/_test/plugin-upload', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message', '插件上传成功');
    }

    public function test_one_byte_over_limit_is_rejected_before_installation(): void
    {
        $file = UploadedFile::fake()->create('plugin.zip', 1, 'application/zip');
        $file->sizeToReport = 64 * 1024 * 1024 + 1;
        $this->plugins->shouldNotReceive('upload');

        $this->post('/_test/plugin-upload', ['file' => $file], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file')
            ->assertJsonPath('errors.file.0', '插件包大小不能超过64 MiB');
    }

    public function test_non_zip_file_is_still_rejected(): void
    {
        $this->plugins->shouldNotReceive('upload');

        $this->post('/_test/plugin-upload', [
            'file' => UploadedFile::fake()->create('plugin.txt', 1, 'text/plain'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', '插件包必须是zip格式');
    }

    public function test_missing_file_is_still_rejected(): void
    {
        $this->plugins->shouldNotReceive('upload');

        $this->postJson('/_test/plugin-upload')
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', '请选择插件包文件');
    }

    public function test_runtime_limits_leave_room_for_multipart_form_data(): void
    {
        $settings = parse_ini_file(base_path('.docker/php/zz-xboard.ini'));
        $fileBytes = 64 * 1024 * 1024;
        $requestBytes = $fileBytes + 4096;

        $this->assertSame($fileBytes, ini_parse_quantity($settings['upload_max_filesize']));
        $this->assertGreaterThanOrEqual($requestBytes, ini_parse_quantity($settings['post_max_size']));
        $this->assertGreaterThanOrEqual($requestBytes, config('octane.swoole.options.package_max_length'));
    }
}
