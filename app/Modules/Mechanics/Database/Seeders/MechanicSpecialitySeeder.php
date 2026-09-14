<?php

declare(strict_types=1);

namespace App\Modules\Mechanics\Database\Seeders;

use App\Modules\Mechanics\Models\MechanicSpeciality;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The controlled list of mechanic specialities.
 *
 * The directory's filter is only as good as this list. It is deliberately
 * short and written in the words a Zambian buyer would use about their own
 * car — "Engine overhaul", not "Powertrain systems" — because somebody whose
 * vehicle will not start is choosing from it on a phone at the roadside.
 *
 * Re-running this refreshes names, descriptions and ordering without
 * disturbing the profiles that already claim a speciality: rows are matched
 * on slug and never deleted. A speciality that is withdrawn is deactivated by
 * staff, so profiles keep the claim and the filter stops offering it.
 */
class MechanicSpecialitySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->specialities() as $position => [$name, $description]) {
            MechanicSpeciality::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'description' => $description,
                    'position' => $position + 1,
                ],
            );
        }
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function specialities(): array
    {
        return [
            ['Engine repair & overhaul', 'Head gaskets, rebuilds, timing, cooling systems.'],
            ['Gearbox & transmission', 'Manual and automatic gearboxes, clutches, differentials.'],
            ['Auto electrics', 'Starting, charging, wiring, lights, immobilisers.'],
            ['Diagnostics & ECU', 'Fault codes, sensors, engine management, remapping.'],
            ['Suspension & steering', 'Shocks, bushes, ball joints, wheel alignment.'],
            ['Brakes', 'Pads, discs, drums, master cylinders, ABS.'],
            ['Air conditioning', 'Regassing, compressors, leak tracing.'],
            ['Diesel & injector systems', 'Pumps, injectors, common rail, turbochargers.'],
            ['Panel beating & spray painting', 'Bodywork, accident repairs, respraying.'],
            ['Welding & fabrication', 'Chassis, exhausts, brackets, roll bars.'],
            ['Tyres & wheels', 'Fitting, balancing, puncture repair, rims.'],
            ['Exhaust systems', 'Silencers, manifolds, catalytic converters.'],
            ['Heavy vehicles & trucks', 'Lorries, buses, tippers and trailers.'],
            ['Motorbikes', 'Motorcycles and three-wheelers.'],
            ['Vehicle inspection & pre-purchase checks', 'Independent checks before a buyer commits.'],
        ];
    }
}
