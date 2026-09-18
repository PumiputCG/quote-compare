<?php

namespace Tests\Unit;

use App\Models\AppUser;
use Tests\TestCase;

class AppUserAvatarUrlTest extends TestCase
{
    public function test_profile_picture_url_uses_insight_public_storage(): void
    {
        config(['app.insight_asset_url' => 'http://insight.test/public/storage/']);

        $user = new AppUser([
            'profile_picture' => 'profiles/example.jpg',
        ]);

        $this->assertSame(
            'http://insight.test/public/storage/profiles/example.jpg',
            $user->profilePictureUrl()
        );
    }

    public function test_profile_picture_url_encodes_each_path_segment(): void
    {
        config(['app.insight_asset_url' => 'http://insight.test/public/storage']);

        $user = new AppUser([
            'profile_picture' => 'profiles/รูป พนักงาน.jpg',
        ]);

        $this->assertSame(
            'http://insight.test/public/storage/profiles/%E0%B8%A3%E0%B8%B9%E0%B8%9B%20%E0%B8%9E%E0%B8%99%E0%B8%B1%E0%B8%81%E0%B8%87%E0%B8%B2%E0%B8%99.jpg',
            $user->profilePictureUrl()
        );
    }

    public function test_profile_picture_url_is_null_when_no_picture_exists(): void
    {
        $user = new AppUser(['profile_picture' => '']);

        $this->assertNull($user->profilePictureUrl());
    }
}
