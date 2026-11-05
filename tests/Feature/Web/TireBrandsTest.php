<?php

namespace Tests\Feature\Web;

use App\Domain\Users\Role;
use App\Models\StorageContract;
use App\Models\TireBrand;
use Database\Seeders\TireBrandSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TireBrandsTest extends TestCase
{
    public function test_migration_fills_brands_without_cyrillic_duplicates(): void
    {
        $names = TireBrand::pluck('name')->all();
        $this->assertCount(count(TireBrandSeeder::brands()), $names);
        $this->assertContains('NorTec', $names);
        $this->assertNotContains('NОRTEC', $names); // с кириллической О
        $this->assertNotContains('NORTEC', $names);
        $this->assertNotContains('Любой', $names);
        $this->assertContains('Белшина', $names);

        (new TireBrandSeeder)->run(); // повторный запуск не плодит дубли
        $this->assertSame(count($names), TireBrand::count());
    }

    public function test_accept_wizard_gets_brands_and_saves_brand_model(): void
    {
        $store = $this->store();
        $seller = $this->user(Role::Seller, $store);
        $this->actingAs($seller)->get('/accept')
            ->assertInertia(fn (Assert $p) => $p->component('Accept/Wizard')->has('tireBrands', TireBrand::count()));

        $this->post('/accept', $this->acceptData($store, ['sets' => [['tire_size' => '205/55 R16 94H', 'brand_model' => 'Michelin X-Ice North 4']]]))
            ->assertRedirect();
        $set = StorageContract::firstOrFail()->sets()->first();
        $this->assertSame('205/55 R16 94H', $set->tire_size);
        $this->assertSame('Michelin X-Ice North 4', $set->brand_model);
    }
}
