<?php

namespace Tests\Feature\Customer;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.media_disk' => 'media-test']);
        Storage::fake('media-test');

        $this->customer = User::factory()->customerWithProfile()->create();
        Passport::actingAs($this->customer, [Role::Customer->scope()]);
    }

    private function avatarPath(): ?string
    {
        return $this->customer->customerProfile->fresh()->avatar_path;
    }

    public function test_uploading_stores_the_file_under_a_random_name(): void
    {
        $response = $this->post('/api/v1/customer/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('../../my photo.png', 300, 300),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = $this->avatarPath();
        $this->assertMatchesRegularExpression('#^avatars/[0-9a-z]{26}\.png$#', $path);
        Storage::disk('media-test')->assertExists($path);
        $this->assertStringEndsWith($path, $response->json('data.avatar_url'));
    }

    public function test_replacing_the_avatar_deletes_the_old_file(): void
    {
        $this->post('/api/v1/customer/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertOk();
        $old = $this->avatarPath();

        $this->post('/api/v1/customer/profile/avatar', ['avatar' => UploadedFile::fake()->image('b.webp')], ['Accept' => 'application/json'])->assertOk();

        Storage::disk('media-test')->assertMissing($old);
        Storage::disk('media-test')->assertExists($this->avatarPath());
        $this->assertCount(1, Storage::disk('media-test')->allFiles('avatars'));
    }

    public function test_removing_the_avatar_deletes_the_file(): void
    {
        $this->post('/api/v1/customer/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json']);
        $old = $this->avatarPath();

        $this->deleteJson('/api/v1/customer/profile/avatar')
            ->assertOk()
            ->assertJsonPath('data.avatar_url', null);

        Storage::disk('media-test')->assertMissing($old);
        $this->assertNull($this->avatarPath());
    }

    /**
     * Uses real files, not UploadedFile::fake(): a fake reports its type from the file name and can
     * report made-up sizes and dimensions, which hides what production validation does with the bytes.
     */
    public function test_only_real_small_jpg_png_or_webp_images_are_accepted(): void
    {
        $cases = [
            'pdf' => $this->realUpload('notes.pdf', "%PDF-1.4\n%fake pdf body\n"),
            'text named .png' => $this->realUpload('fake.png', 'not really an image'),
            'over 2 MB' => $this->realUpload('big.png', $this->pngBytes(10, 10).str_repeat("\0", 3 * 1024 * 1024)),
            'gif' => $this->realUpload('anim.gif', $this->gifBytes()),
        ];

        foreach ($cases as $case => $file) {
            $response = $this->post('/api/v1/customer/profile/avatar', ['avatar' => $file], ['Accept' => 'application/json']);
            $this->assertSame(422, $response->status(), "Expected 422 for: {$case}");
            $response->assertJsonValidationErrors('avatar');
        }

        $this->assertSame([], Storage::disk('media-test')->allFiles());
    }

    public function test_a_real_png_is_accepted(): void
    {
        $this->post('/api/v1/customer/profile/avatar', ['avatar' => $this->realUpload('me.png', $this->pngBytes(200, 200))], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertStringEndsWith('.png', $this->avatarPath());
    }

    /** A file on disk wrapped like a browser upload; its type is detected from the content. */
    private function realUpload(string $clientName, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $bytes);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return new UploadedFile($path, $clientName, null, null, true);
    }

    private function pngBytes(int $width, int $height): string
    {
        ob_start();
        imagepng(imagecreatetruecolor($width, $height));

        return ob_get_clean();
    }

    private function gifBytes(): string
    {
        ob_start();
        imagegif(imagecreatetruecolor(10, 10));

        return ob_get_clean();
    }

    public function test_the_avatar_path_cannot_be_set_through_the_profile_update(): void
    {
        $this->putJson('/api/v1/customer/profile', [
            'name' => 'Ravi', 'phone' => '9876543210', 'avatar_path' => 'avatars/someone-else.jpg',
        ])->assertOk()->assertJsonPath('data.avatar_url', null);
    }

    public function test_an_avatar_needs_a_profile_first(): void
    {
        Passport::actingAs(User::factory()->customer()->create(), [Role::Customer->scope()]);

        $this->post('/api/v1/customer/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
            ->assertConflict();
        $this->assertSame([], Storage::disk('media-test')->allFiles());
    }
}
