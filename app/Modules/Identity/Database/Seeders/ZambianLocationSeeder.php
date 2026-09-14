<?php

declare(strict_types=1);

namespace App\Modules\Identity\Database\Seeders;

use App\Modules\Identity\Models\City;
use App\Modules\Identity\Models\Province;
use App\Modules\Identity\Services\LocationDirectory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Zambia's ten provinces and the towns MonaFind trades in.
 *
 * Provincial capitals and the larger towns are marked major so address
 * pickers put them first — most buyers are in one of a dozen places, and
 * making them scroll past every district centre is a real cost on a slow
 * phone.
 *
 * Re-running this adds new towns without disturbing addresses that already
 * point at existing ones.
 */
class ZambianLocationSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->provinces() as $position => $definition) {
            $province = Province::query()->updateOrCreate(
                ['slug' => Str::slug($definition['name'])],
                [
                    'name' => $definition['name'],
                    'capital' => $definition['capital'],
                    'position' => $position + 1,
                ],
            );

            foreach ($definition['cities'] as $name => $coordinates) {
                City::query()->updateOrCreate(
                    ['province_id' => $province->id, 'slug' => Str::slug($name)],
                    [
                        'name' => $name,
                        'latitude' => $coordinates[0],
                        'longitude' => $coordinates[1],
                        'is_major' => $name === $definition['capital'] || in_array($name, $definition['major'], true),
                    ],
                );
            }
        }

        app(LocationDirectory::class)->flush();
    }

    /**
     * @return array<int, array{name: string, capital: string, major: array<int, string>, cities: array<string, array{0: float, 1: float}>}>
     */
    private function provinces(): array
    {
        return [
            [
                'name' => 'Lusaka',
                'capital' => 'Lusaka',
                'major' => ['Chilanga', 'Kafue'],
                'cities' => [
                    'Lusaka' => [-15.3875, 28.3228],
                    'Chilanga' => [-15.5586, 28.2842],
                    'Kafue' => [-15.7690, 28.1814],
                    'Chongwe' => [-15.3286, 28.6817],
                    'Luangwa' => [-15.6167, 30.4167],
                    'Rufunsa' => [-15.0667, 29.6333],
                    'Chirundu' => [-16.0403, 28.8497],
                ],
            ],
            [
                'name' => 'Copperbelt',
                'capital' => 'Ndola',
                'major' => ['Kitwe', 'Chingola', 'Mufulira', 'Luanshya'],
                'cities' => [
                    'Ndola' => [-12.9587, 28.6366],
                    'Kitwe' => [-12.8024, 28.2132],
                    'Chingola' => [-12.5288, 27.8492],
                    'Mufulira' => [-12.5497, 28.2408],
                    'Luanshya' => [-13.1367, 28.4166],
                    'Chililabombwe' => [-12.3667, 27.8333],
                    'Kalulushi' => [-12.8394, 28.0942],
                    'Masaiti' => [-13.2500, 28.4167],
                    'Mpongwe' => [-13.5100, 28.1533],
                    'Lufwanyama' => [-13.0000, 27.5000],
                ],
            ],
            [
                'name' => 'Southern',
                'capital' => 'Choma',
                'major' => ['Livingstone', 'Mazabuka', 'Monze'],
                'cities' => [
                    'Choma' => [-16.8089, 26.9819],
                    'Livingstone' => [-17.8419, 25.8544],
                    'Mazabuka' => [-15.8569, 27.7500],
                    'Monze' => [-16.2833, 27.4833],
                    'Kalomo' => [-17.0333, 26.4833],
                    'Siavonga' => [-16.5386, 28.7089],
                    'Kazungula' => [-17.7833, 25.2667],
                    'Namwala' => [-15.7500, 26.4333],
                    'Gwembe' => [-16.5000, 27.6167],
                    'Pemba' => [-16.5167, 27.3667],
                ],
            ],
            [
                'name' => 'Central',
                'capital' => 'Kabwe',
                'major' => ['Kapiri Mposhi', 'Mkushi'],
                'cities' => [
                    'Kabwe' => [-14.4469, 28.4464],
                    'Kapiri Mposhi' => [-13.9711, 28.6689],
                    'Mkushi' => [-13.6208, 29.3925],
                    'Serenje' => [-13.2333, 30.2333],
                    'Mumbwa' => [-14.9833, 27.0667],
                    'Chibombo' => [-14.6564, 28.0700],
                    'Itezhi-Tezhi' => [-15.7500, 26.0333],
                ],
            ],
            [
                'name' => 'Eastern',
                'capital' => 'Chipata',
                'major' => ['Petauke', 'Katete'],
                'cities' => [
                    'Chipata' => [-13.6333, 32.6500],
                    'Petauke' => [-14.2444, 31.3208],
                    'Katete' => [-14.0667, 32.0500],
                    'Lundazi' => [-12.2833, 33.1833],
                    'Nyimba' => [-14.5583, 30.8181],
                    'Chadiza' => [-14.0667, 32.4333],
                    'Mambwe' => [-13.0500, 31.9333],
                ],
            ],
            [
                'name' => 'Northern',
                'capital' => 'Kasama',
                'major' => ['Mpika', 'Mbala'],
                'cities' => [
                    'Kasama' => [-10.2129, 31.1808],
                    'Mpika' => [-11.8333, 31.4500],
                    'Mbala' => [-8.8375, 31.3661],
                    'Mpulungu' => [-8.7639, 31.1139],
                    'Luwingu' => [-10.2667, 29.9167],
                    'Mungwi' => [-10.1667, 31.5000],
                    'Chilubi' => [-10.4667, 30.2167],
                ],
            ],
            [
                'name' => 'Luapula',
                'capital' => 'Mansa',
                'major' => ['Nchelenge', 'Samfya'],
                'cities' => [
                    'Mansa' => [-11.1997, 28.8940],
                    'Nchelenge' => [-9.3472, 28.7333],
                    'Samfya' => [-11.3644, 29.5561],
                    'Kawambwa' => [-9.7917, 29.0778],
                    'Mwense' => [-10.3833, 28.7000],
                    'Chiengi' => [-8.6500, 29.1667],
                ],
            ],
            [
                'name' => 'North-Western',
                'capital' => 'Solwezi',
                'major' => ['Kasempa', 'Mwinilunga'],
                'cities' => [
                    'Solwezi' => [-12.1687, 26.3833],
                    'Kasempa' => [-13.4583, 25.8333],
                    'Mwinilunga' => [-11.7333, 24.4333],
                    'Zambezi' => [-13.5453, 23.1069],
                    'Kabompo' => [-13.5947, 24.2000],
                    'Mufumbwe' => [-13.6833, 24.8000],
                    'Chavuma' => [-13.0833, 22.6833],
                ],
            ],
            [
                'name' => 'Western',
                'capital' => 'Mongu',
                'major' => ['Kaoma', 'Senanga'],
                'cities' => [
                    'Mongu' => [-15.2543, 23.1268],
                    'Kaoma' => [-14.7972, 24.8000],
                    'Senanga' => [-16.1167, 23.2667],
                    'Sesheke' => [-17.4761, 24.2989],
                    'Kalabo' => [-14.9972, 22.6792],
                    'Lukulu' => [-14.3833, 23.2333],
                    'Shangombo' => [-16.4667, 22.1833],
                ],
            ],
            [
                'name' => 'Muchinga',
                'capital' => 'Chinsali',
                'major' => ['Nakonde', 'Isoka'],
                'cities' => [
                    'Chinsali' => [-10.5500, 32.0667],
                    'Nakonde' => [-9.3333, 32.7500],
                    'Isoka' => [-10.1333, 32.6333],
                    'Mafinga' => [-10.1667, 33.2000],
                    'Shiwa Ng’andu' => [-11.2000, 31.7500],
                    'Chama' => [-11.1833, 33.1500],
                ],
            ],
        ];
    }
}
