<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Database\Seeders;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\CategoryTree;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The three-level part category tree: system → group → part.
 *
 * Sellers list against the leaves and buyers browse from the roots, so the
 * middle level exists to keep either end usable — "Engine" alone is too broad
 * to browse and "Crankshaft position sensor" alone is too many to scroll.
 *
 * Placement goes through CategoryTree because it owns the derived `depth` and
 * `path` columns; a seeder writing them itself is how a tree ends up lying
 * about its own shape.
 *
 * Re-running this adds new categories without moving listings that already
 * point at existing ones.
 */
class PartCategorySeeder extends Seeder
{
    public function run(CategoryTree $tree): void
    {
        foreach ($this->categories() as $rootName => $groups) {
            $root = $this->upsert($tree, $rootName, null);

            foreach ($groups as $groupName => $parts) {
                $group = $this->upsert($tree, $groupName, $root);

                foreach ($parts as $partName) {
                    $this->upsert($tree, $partName, $group);
                }
            }
        }
    }

    /**
     * Create the node if it is new, and leave it where it is if it is not.
     *
     * A category that has been renamed or moved by staff stays as they left
     * it: the seeder supplies a starting tree, it does not own it forever.
     */
    private function upsert(CategoryTree $tree, string $name, ?Category $parent): Category
    {
        $existing = Category::query()->where('slug', Str::slug($name))->first();

        if ($existing !== null) {
            return $existing;
        }

        return $tree->create(['name' => $name], $parent);
    }

    /**
     * @return array<string, array<string, array<int, string>>>
     */
    private function categories(): array
    {
        return [
            'Engine' => [
                'Engine internals' => [
                    'Pistons and rings',
                    'Crankshafts',
                    'Camshafts',
                    'Cylinder heads',
                    'Engine gaskets',
                    'Timing belts and chains',
                ],
                'Fuel system' => [
                    'Fuel injectors',
                    'Fuel pumps',
                    'Fuel filters',
                    'Carburettors',
                    'Injector pumps',
                ],
                'Cooling system' => [
                    'Radiators',
                    'Water pumps',
                    'Thermostats',
                    'Cooling fans',
                    'Hoses and clamps',
                ],
                'Air intake and exhaust' => [
                    'Air filters',
                    'Turbochargers',
                    'Intercoolers',
                    'Exhaust manifolds',
                    'Silencers',
                ],
                'Complete engines' => [
                    'Petrol engines',
                    'Diesel engines',
                    'Engine mounts',
                ],
            ],
            'Transmission and drivetrain' => [
                'Gearbox' => [
                    'Manual gearboxes',
                    'Automatic gearboxes',
                    'Gearbox mounts',
                    'Gear linkages',
                ],
                'Clutch' => [
                    'Clutch kits',
                    'Clutch plates',
                    'Pressure plates',
                    'Release bearings',
                    'Clutch master cylinders',
                ],
                'Axles and differentials' => [
                    'Differentials',
                    'Driveshafts',
                    'CV joints',
                    'Wheel hubs',
                    'Transfer cases',
                ],
            ],
            'Suspension and steering' => [
                'Suspension' => [
                    'Shock absorbers',
                    'Coil springs',
                    'Leaf springs',
                    'Control arms',
                    'Bushes and mountings',
                    'Ball joints',
                ],
                'Steering' => [
                    'Steering racks',
                    'Power steering pumps',
                    'Tie rod ends',
                    'Steering columns',
                ],
            ],
            'Brakes' => [
                'Friction parts' => [
                    'Brake pads',
                    'Brake shoes',
                    'Brake discs',
                    'Brake drums',
                ],
                'Hydraulics' => [
                    'Brake calipers',
                    'Master cylinders',
                    'Wheel cylinders',
                    'Brake hoses',
                ],
                'ABS' => [
                    'ABS pumps',
                    'ABS sensors',
                ],
            ],
            'Electrical' => [
                'Starting and charging' => [
                    'Starter motors',
                    'Alternators',
                    'Batteries',
                    'Voltage regulators',
                ],
                'Ignition' => [
                    'Spark plugs',
                    'Ignition coils',
                    'Glow plugs',
                    'Distributors',
                ],
                'Sensors and modules' => [
                    'Oxygen sensors',
                    'Crankshaft sensors',
                    'Mass airflow sensors',
                    'Engine control units',
                ],
                'Lighting' => [
                    'Headlamps',
                    'Tail lamps',
                    'Indicators',
                    'Bulbs',
                ],
            ],
            'Body and exterior' => [
                'Panels' => [
                    'Bonnets',
                    'Doors',
                    'Wings and fenders',
                    'Bumpers',
                    'Tailgates',
                ],
                'Glass and mirrors' => [
                    'Windscreens',
                    'Door glass',
                    'Wing mirrors',
                    'Mirror glass',
                ],
                'Trim and accessories' => [
                    'Grilles',
                    'Roof racks',
                    'Bull bars',
                    'Mud flaps',
                ],
            ],
            'Interior' => [
                'Cabin' => [
                    'Seats',
                    'Dashboards',
                    'Door cards',
                    'Carpets and mats',
                ],
                'Controls and instruments' => [
                    'Instrument clusters',
                    'Switches',
                    'Steering wheels',
                    'Pedal assemblies',
                ],
                'Climate control' => [
                    'Air conditioning compressors',
                    'Heater matrices',
                    'Blower motors',
                    'Cabin filters',
                ],
            ],
            'Wheels and tyres' => [
                'Wheels' => [
                    'Alloy wheels',
                    'Steel wheels',
                    'Wheel nuts and studs',
                ],
                'Tyres' => [
                    'Passenger tyres',
                    'Four-by-four tyres',
                    'Light truck tyres',
                ],
            ],
            'Service parts and fluids' => [
                'Filters' => [
                    'Oil filters',
                    'Diesel filters',
                    'Hydraulic filters',
                ],
                'Fluids and lubricants' => [
                    'Engine oil',
                    'Gear oil',
                    'Brake fluid',
                    'Coolant',
                ],
                'Wear items' => [
                    'Wiper blades',
                    'Drive belts',
                    'Bearings',
                ],
            ],
        ];
    }
}
