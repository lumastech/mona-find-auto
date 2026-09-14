<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Database\Seeders;

use App\Modules\Catalog\Models\Make;
use App\Modules\Catalog\Models\VehicleModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The makes and models MonaFind actually sees.
 *
 * Zambian traffic is overwhelmingly Japanese used imports — Toyota, Nissan,
 * Mitsubishi, Honda, Mazda — with German marques, Land Rover and the Indian
 * and Chinese pickups behind them. Those are marked popular so they sit at
 * the top of every picker, which saves a scroll on the phones most sellers
 * list from.
 *
 * Re-running this adds new models without disturbing listings that already
 * point at existing ones.
 */
class VehicleReferenceSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->makes() as $position => $definition) {
            $make = Make::query()->updateOrCreate(
                ['slug' => Str::slug($definition['name'])],
                [
                    'name' => $definition['name'],
                    'country' => $definition['country'],
                    'is_popular' => $definition['popular'],
                    'position' => $position + 1,
                    'is_active' => true,
                ],
            );

            foreach ($definition['models'] as $name => $years) {
                VehicleModel::query()->updateOrCreate(
                    ['make_id' => $make->id, 'slug' => Str::slug($name)],
                    [
                        'name' => $name,
                        'production_start_year' => $years[0],
                        'production_end_year' => $years[1],
                        'is_popular' => $definition['popular'],
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    /**
     * Twenty marques, each with the models seen on Zambian roads.
     *
     * The year pairs are production runs; a null end year means the model is
     * still being built, so any recent year is a plausible fitment.
     *
     * @return array<int, array{name: string, country: string, popular: bool, models: array<string, array{0: int, 1: int|null}>}>
     */
    private function makes(): array
    {
        return [
            ['name' => 'Toyota', 'country' => 'Japan', 'popular' => true, 'models' => [
                'Hilux' => [1997, null],
                'Corolla' => [1995, null],
                'Land Cruiser' => [1990, null],
                'Land Cruiser Prado' => [1996, null],
                'RAV4' => [1994, null],
                'Hiace' => [1989, null],
                'Vitz' => [1999, null],
                'Premio' => [2001, null],
                'Fortuner' => [2005, null],
                'Noah' => [2001, null],
            ]],
            ['name' => 'Nissan', 'country' => 'Japan', 'popular' => true, 'models' => [
                'Hardbody' => [1985, 2015],
                'Navara' => [1997, null],
                'X-Trail' => [2000, null],
                'Note' => [2004, null],
                'March' => [1992, null],
                'Patrol' => [1987, null],
                'Caravan' => [1986, null],
            ]],
            ['name' => 'Mitsubishi', 'country' => 'Japan', 'popular' => true, 'models' => [
                'Pajero' => [1991, null],
                'Triton' => [2005, null],
                'Canter' => [1985, null],
                'Colt' => [1992, 2012],
                'Outlander' => [2001, null],
            ]],
            ['name' => 'Honda', 'country' => 'Japan', 'popular' => true, 'models' => [
                'Fit' => [2001, null],
                'CR-V' => [1995, null],
                'Civic' => [1992, null],
                'Accord' => [1993, null],
            ]],
            ['name' => 'Mazda', 'country' => 'Japan', 'popular' => true, 'models' => [
                'Demio' => [1996, null],
                'BT-50' => [2006, null],
                'CX-5' => [2012, null],
                'Familia' => [1994, 2004],
            ]],
            ['name' => 'Isuzu', 'country' => 'Japan', 'popular' => true, 'models' => [
                'D-Max' => [2002, null],
                'KB' => [1988, 2012],
                'NPR' => [1990, null],
                'MU-X' => [2013, null],
            ]],
            ['name' => 'Ford', 'country' => 'United States', 'popular' => true, 'models' => [
                'Ranger' => [1998, null],
                'Everest' => [2003, null],
                'Focus' => [1998, null],
                'Fiesta' => [1995, null],
            ]],
            ['name' => 'Volkswagen', 'country' => 'Germany', 'popular' => true, 'models' => [
                'Polo' => [1994, null],
                'Golf' => [1990, null],
                'Amarok' => [2010, null],
                'Jetta' => [1992, null],
            ]],
            ['name' => 'Hino', 'country' => 'Japan', 'popular' => false, 'models' => [
                'Dutro' => [1999, null],
                'Ranger' => [1989, null],
            ]],
            ['name' => 'Land Rover', 'country' => 'United Kingdom', 'popular' => true, 'models' => [
                'Defender' => [1990, null],
                'Discovery' => [1989, null],
                'Range Rover' => [1994, null],
                'Freelander' => [1997, 2014],
            ]],
            ['name' => 'Mercedes-Benz', 'country' => 'Germany', 'popular' => true, 'models' => [
                'Sprinter' => [1995, null],
                'C-Class' => [1993, null],
                'E-Class' => [1993, null],
                'Actros' => [1996, null],
            ]],
            ['name' => 'BMW', 'country' => 'Germany', 'popular' => false, 'models' => [
                '3 Series' => [1990, null],
                '5 Series' => [1988, null],
                'X5' => [1999, null],
            ]],
            ['name' => 'Hyundai', 'country' => 'South Korea', 'popular' => false, 'models' => [
                'Tucson' => [2004, null],
                'H100' => [1993, null],
                'Elantra' => [1990, null],
                'Santa Fe' => [2000, null],
            ]],
            ['name' => 'Kia', 'country' => 'South Korea', 'popular' => false, 'models' => [
                'Sportage' => [1993, null],
                'Rio' => [2000, null],
                'K2700' => [1999, null],
            ]],
            ['name' => 'Tata', 'country' => 'India', 'popular' => false, 'models' => [
                'Xenon' => [2007, null],
                'Super Ace' => [2010, null],
            ]],
            ['name' => 'Mahindra', 'country' => 'India', 'popular' => false, 'models' => [
                'Scorpio' => [2002, null],
                'Bolero' => [2000, null],
                'Pik Up' => [2006, null],
            ]],
            ['name' => 'Suzuki', 'country' => 'Japan', 'popular' => false, 'models' => [
                'Alto' => [1994, null],
                'Swift' => [2004, null],
                'Jimny' => [1998, null],
            ]],
            ['name' => 'Subaru', 'country' => 'Japan', 'popular' => false, 'models' => [
                'Forester' => [1997, null],
                'Impreza' => [1992, null],
                'Outback' => [1995, null],
            ]],
            ['name' => 'Chevrolet', 'country' => 'United States', 'popular' => false, 'models' => [
                'Aveo' => [2002, 2011],
                'Trailblazer' => [2002, null],
                'Spark' => [2005, null],
            ]],
            ['name' => 'Foton', 'country' => 'China', 'popular' => false, 'models' => [
                'Tunland' => [2011, null],
                'Aumark' => [2008, null],
            ]],
        ];
    }
}
