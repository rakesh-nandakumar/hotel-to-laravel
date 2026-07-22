<?php

namespace Database\Seeders\Demo;

use App\Models\Branch;
use App\Models\Hotel\Ingredient;
use App\Models\Hotel\IngredientBatch;
use App\Models\Hotel\LaundryItem;
use App\Models\Hotel\MenuCategory;
use App\Models\Hotel\MenuItem;
use App\Models\Hotel\RecipeItem;
use App\Models\Hotel\Venue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Restaurant + laundry + venue catalogs. `pos_menu_categories`/`pos_menu_items`
 * ship empty (MenuSeeder only seeds the unrelated nav-menu tree), so this
 * builds the whole POS menu from scratch, wired to real ingredient stock so
 * later POS orders (DemoShiftsOrdersSeeder) actually deduct it.
 */
class DemoCatalogSeeder extends Seeder
{
    /**
     * @var list<array{name: string, unit: string, stock: float, low: float, perishable: bool, forceAlert?: bool}>
     */
    private const INGREDIENTS = [
        ['name' => 'Chicken', 'unit' => 'kg', 'stock' => 40, 'low' => 8, 'perishable' => true],
        ['name' => 'Seer Fish', 'unit' => 'kg', 'stock' => 20, 'low' => 5, 'perishable' => true, 'forceAlert' => true],
        ['name' => 'Prawns', 'unit' => 'kg', 'stock' => 15, 'low' => 4, 'perishable' => true, 'forceAlert' => true],
        ['name' => 'Crab', 'unit' => 'kg', 'stock' => 8, 'low' => 2, 'perishable' => true],
        ['name' => 'Squid', 'unit' => 'kg', 'stock' => 8, 'low' => 2, 'perishable' => true],
        ['name' => 'Beef', 'unit' => 'kg', 'stock' => 15, 'low' => 4, 'perishable' => true],
        ['name' => 'Basmati Rice', 'unit' => 'kg', 'stock' => 60, 'low' => 10, 'perishable' => false],
        ['name' => 'Red Lentils (Dhal)', 'unit' => 'kg', 'stock' => 20, 'low' => 5, 'perishable' => false],
        ['name' => 'Coconut Milk', 'unit' => 'l', 'stock' => 30, 'low' => 6, 'perishable' => true],
        ['name' => 'Mixed Vegetables', 'unit' => 'kg', 'stock' => 25, 'low' => 5, 'perishable' => true],
        ['name' => 'Potato', 'unit' => 'kg', 'stock' => 30, 'low' => 6, 'perishable' => false],
        ['name' => 'Cheese', 'unit' => 'kg', 'stock' => 8, 'low' => 2, 'perishable' => true],
        ['name' => 'Bread Loaf', 'unit' => 'units', 'stock' => 40, 'low' => 10, 'perishable' => true],
        ['name' => 'Eggs', 'unit' => 'units', 'stock' => 200, 'low' => 40, 'perishable' => true],
        ['name' => 'Ice Cream', 'unit' => 'l', 'stock' => 15, 'low' => 3, 'perishable' => true],
        ['name' => 'Fresh Fruit Mix', 'unit' => 'kg', 'stock' => 20, 'low' => 4, 'perishable' => true],
        ['name' => 'Soft Drink Cans', 'unit' => 'units', 'stock' => 150, 'low' => 30, 'perishable' => false],
        ['name' => 'Beer Bottles', 'unit' => 'units', 'stock' => 100, 'low' => 20, 'perishable' => false],
        ['name' => 'Mineral Water Bottles', 'unit' => 'units', 'stock' => 200, 'low' => 40, 'perishable' => false],
        ['name' => 'Ceylon Tea Leaves', 'unit' => 'kg', 'stock' => 5, 'low' => 1, 'perishable' => false],
        ['name' => 'Coffee Beans', 'unit' => 'kg', 'stock' => 6, 'low' => 1, 'perishable' => false],
        ['name' => 'Cooking Oil', 'unit' => 'l', 'stock' => 25, 'low' => 5, 'perishable' => false],
        ['name' => 'Flour', 'unit' => 'kg', 'stock' => 30, 'low' => 6, 'perishable' => false],
        ['name' => 'Sugar', 'unit' => 'kg', 'stock' => 20, 'low' => 4, 'perishable' => false],
        ['name' => 'Milk', 'unit' => 'l', 'stock' => 25, 'low' => 5, 'perishable' => true, 'forceAlert' => true],
        ['name' => 'Spice Mix', 'unit' => 'kg', 'stock' => 10, 'low' => 2, 'perishable' => false],
    ];

    /**
     * @var list<array{name: string, is_minibar?: bool, items: list<array{name: string, price: int, recipe?: array<string, float>}>}>
     */
    private const CATEGORIES = [
        ['name' => 'Appetizers', 'items' => [
            ['name' => 'Fish Cutlets', 'price' => 500, 'recipe' => ['Seer Fish' => 0.15, 'Potato' => 0.1]],
            ['name' => 'Chicken Rolls', 'price' => 450, 'recipe' => ['Chicken' => 0.12, 'Flour' => 0.05]],
            ['name' => 'Devilled Cashews', 'price' => 600, 'recipe' => ['Spice Mix' => 0.03]],
            ['name' => 'Prawn Vadai', 'price' => 700, 'recipe' => ['Prawns' => 0.1, 'Flour' => 0.05]],
            ['name' => 'Vegetable Cutlets', 'price' => 400, 'recipe' => ['Mixed Vegetables' => 0.15, 'Potato' => 0.1]],
            ['name' => 'BBQ Chicken Wings', 'price' => 750, 'recipe' => ['Chicken' => 0.2, 'Spice Mix' => 0.02]],
        ]],
        ['name' => 'Soups', 'items' => [
            ['name' => 'Cream of Chicken Soup', 'price' => 450, 'recipe' => ['Chicken' => 0.08, 'Milk' => 0.1]],
            ['name' => 'Sweet Corn Soup', 'price' => 400, 'recipe' => ['Mixed Vegetables' => 0.1]],
            ['name' => 'Seafood Soup', 'price' => 550, 'recipe' => ['Seer Fish' => 0.05, 'Prawns' => 0.05]],
            ['name' => 'Mulligatawny Soup', 'price' => 450, 'recipe' => ['Red Lentils (Dhal)' => 0.1, 'Coconut Milk' => 0.1]],
        ]],
        ['name' => 'Rice & Curry', 'items' => [
            ['name' => 'Chicken Rice & Curry', 'price' => 900, 'recipe' => ['Chicken' => 0.25, 'Basmati Rice' => 0.2, 'Red Lentils (Dhal)' => 0.1, 'Coconut Milk' => 0.15]],
            ['name' => 'Fish Rice & Curry', 'price' => 950, 'recipe' => ['Seer Fish' => 0.25, 'Basmati Rice' => 0.2, 'Coconut Milk' => 0.15]],
            ['name' => 'Beef Rice & Curry', 'price' => 1100, 'recipe' => ['Beef' => 0.25, 'Basmati Rice' => 0.2, 'Coconut Milk' => 0.15]],
            ['name' => 'Vegetable Rice & Curry', 'price' => 700, 'recipe' => ['Mixed Vegetables' => 0.3, 'Basmati Rice' => 0.2, 'Red Lentils (Dhal)' => 0.1, 'Coconut Milk' => 0.1]],
            ['name' => 'Egg Rice & Curry', 'price' => 650, 'recipe' => ['Eggs' => 2, 'Basmati Rice' => 0.2, 'Red Lentils (Dhal)' => 0.1]],
            ['name' => 'Chicken Kottu Roti', 'price' => 850, 'recipe' => ['Chicken' => 0.2, 'Bread Loaf' => 0.5, 'Eggs' => 1]],
        ]],
        ['name' => 'Seafood Specials', 'items' => [
            ['name' => 'Grilled Seer Fish', 'price' => 1400, 'recipe' => ['Seer Fish' => 0.3]],
            ['name' => 'Prawn Curry', 'price' => 1300, 'recipe' => ['Prawns' => 0.25, 'Coconut Milk' => 0.15]],
            ['name' => 'Crab Curry (Whole)', 'price' => 2200, 'recipe' => ['Crab' => 0.4, 'Coconut Milk' => 0.15]],
            ['name' => 'Calamari Fry', 'price' => 1200, 'recipe' => ['Squid' => 0.25]],
        ]],
        ['name' => 'Grilled & BBQ', 'items' => [
            ['name' => 'BBQ Chicken Platter', 'price' => 1600, 'recipe' => ['Chicken' => 0.4]],
            ['name' => 'Mixed Grill Platter', 'price' => 2200, 'recipe' => ['Chicken' => 0.2, 'Beef' => 0.2, 'Seer Fish' => 0.15]],
            ['name' => 'Beef Steak', 'price' => 1800, 'recipe' => ['Beef' => 0.3]],
            ['name' => 'Grilled Vegetable Skewers', 'price' => 750, 'recipe' => ['Mixed Vegetables' => 0.3]],
        ]],
        ['name' => 'Desserts', 'items' => [
            ['name' => 'Watalappan', 'price' => 350, 'recipe' => ['Coconut Milk' => 0.1, 'Eggs' => 2]],
            ['name' => 'Ice Cream Sundae', 'price' => 400, 'recipe' => ['Ice Cream' => 0.2]],
            ['name' => 'Fresh Fruit Platter', 'price' => 450, 'recipe' => ['Fresh Fruit Mix' => 0.3]],
            ['name' => 'Chocolate Cake Slice', 'price' => 500, 'recipe' => ['Flour' => 0.05, 'Sugar' => 0.05]],
        ]],
        ['name' => 'Beverages', 'items' => [
            ['name' => 'Fresh Lime Juice', 'price' => 300, 'recipe' => ['Sugar' => 0.02]],
            ['name' => 'Ceylon Tea (Pot)', 'price' => 250, 'recipe' => ['Ceylon Tea Leaves' => 0.02, 'Milk' => 0.05]],
            ['name' => 'Cappuccino', 'price' => 450, 'recipe' => ['Coffee Beans' => 0.02, 'Milk' => 0.1]],
            ['name' => 'Soft Drink (Can)', 'price' => 250, 'recipe' => ['Soft Drink Cans' => 1]],
            ['name' => 'Fresh Fruit Juice', 'price' => 350, 'recipe' => ['Fresh Fruit Mix' => 0.15]],
            ['name' => 'Local Beer (Bottle)', 'price' => 650, 'recipe' => ['Beer Bottles' => 1]],
        ]],
        ['name' => 'Minibar', 'is_minibar' => true, 'items' => [
            ['name' => 'Minibar Water 250ml', 'price' => 150, 'recipe' => ['Mineral Water Bottles' => 1]],
            ['name' => 'Minibar Soft Drink', 'price' => 300, 'recipe' => ['Soft Drink Cans' => 1]],
            ['name' => 'Minibar Beer', 'price' => 750, 'recipe' => ['Beer Bottles' => 1]],
            ['name' => 'Minibar Mixed Nuts', 'price' => 450],
            ['name' => 'Minibar Chocolate Bar', 'price' => 400],
        ]],
    ];

    /** @var list<array{name: string, price: int}> */
    private const LAUNDRY_ITEMS = [
        ['name' => 'Shirt', 'price' => 250],
        ['name' => 'Trousers', 'price' => 300],
        ['name' => 'Dress (Formal)', 'price' => 600],
        ['name' => 'Saree', 'price' => 900],
        ['name' => 'Bedsheet (Single)', 'price' => 350],
        ['name' => 'Bedsheet (Double)', 'price' => 450],
        ['name' => 'Towel', 'price' => 150],
        ['name' => 'Suit (2-piece)', 'price' => 1200],
        ['name' => 'Curtain Panel', 'price' => 800],
        ['name' => 'Blanket', 'price' => 700],
    ];

    /** @var list<array{name: string, max_capacity: int, hourly: int, half_day: int, full_day: int, facilities: list<string>}> */
    private const VENUES = [
        ['name' => 'Grand Ballroom', 'max_capacity' => 300, 'hourly' => 15000, 'half_day' => 60000, 'full_day' => 100000, 'facilities' => ['Stage', 'Sound System', 'Projector', 'Air Conditioning', 'Dance Floor']],
        ['name' => 'Garden Pavilion', 'max_capacity' => 150, 'hourly' => 10000, 'half_day' => 40000, 'full_day' => 70000, 'facilities' => ['Open Air', 'Garden View', 'String Lights']],
        ['name' => 'Conference Room A', 'max_capacity' => 40, 'hourly' => 5000, 'half_day' => 18000, 'full_day' => 30000, 'facilities' => ['Projector', 'Whiteboard', 'WiFi', 'Air Conditioning']],
        ['name' => 'Poolside Deck', 'max_capacity' => 100, 'hourly' => 12000, 'half_day' => 45000, 'full_day' => 75000, 'facilities' => ['Pool View', 'Bar Counter']],
    ];

    public function run(): void
    {
        $ingredients = $this->seedIngredients();
        $this->seedMenu($ingredients);
        $this->seedLaundry();
        $this->seedVenues();
    }

    /**
     * @return array<string, Ingredient>
     */
    private function seedIngredients(): array
    {
        $byName = [];

        foreach (self::INGREDIENTS as $def) {
            $ingredient = Ingredient::query()->firstOrCreate(
                ['name' => $def['name']],
                ['unit' => $def['unit'], 'stock_qty' => 0, 'low_stock_threshold' => $def['low']],
            );

            if ($ingredient->wasRecentlyCreated) {
                $this->seedBatches($ingredient, $def);
            }

            $byName[$def['name']] = $ingredient;
        }

        return $byName;
    }

    /**
     * @param  array{stock: float, perishable: bool, forceAlert?: bool}  $def
     */
    private function seedBatches(Ingredient $ingredient, array $def): void
    {
        $now = Carbon::now();
        $older = round($def['stock'] * 0.6, 2);
        $newer = round($def['stock'] - $older, 2);

        $oldExpiryDays = match (true) {
            ! empty($def['forceAlert']) => rand(-2, 2), // already-expired or inside the warning window
            $def['perishable'] => rand(8, 20),
            default => rand(150, 365),
        };
        $newExpiryDays = $def['perishable'] ? rand(15, 30) : rand(200, 400);

        IngredientBatch::create([
            'ingredient_id' => $ingredient->id,
            'qty' => $older,
            'initial_qty' => $older,
            'expiry_date' => $now->copy()->addDays($oldExpiryDays)->toDateString(),
            'received_at' => $now->copy()->subDays(rand(18, 25)),
            'note' => 'Initial stock — bulk delivery',
        ]);

        IngredientBatch::create([
            'ingredient_id' => $ingredient->id,
            'qty' => $newer,
            'initial_qty' => $newer,
            'expiry_date' => $now->copy()->addDays($newExpiryDays)->toDateString(),
            'received_at' => $now->copy()->subDays(rand(2, 6)),
            'note' => 'Restock delivery',
        ]);

        $ingredient->update(['stock_qty' => $def['stock']]);
    }

    /**
     * @param  array<string, Ingredient>  $ingredients
     */
    private function seedMenu(array $ingredients): void
    {
        foreach (self::CATEGORIES as $catIndex => $catDef) {
            $category = MenuCategory::query()->firstOrCreate(
                ['name' => $catDef['name']],
                ['sort_order' => $catIndex, 'is_minibar' => $catDef['is_minibar'] ?? false, 'active' => true],
            );

            foreach ($catDef['items'] as $itemIndex => $itemDef) {
                $menuItem = MenuItem::query()->firstOrCreate(
                    ['name' => $itemDef['name']],
                    [
                        'item_no' => ($catIndex + 1) * 100 + $itemIndex + 1,
                        'menu_category_id' => $category->id,
                        'price' => $itemDef['price'] * 100,
                        'description' => $itemDef['description'] ?? '',
                        'sold_out' => false,
                        'active' => true,
                    ],
                );

                if ($menuItem->wasRecentlyCreated) {
                    foreach ($itemDef['recipe'] ?? [] as $ingredientName => $qty) {
                        RecipeItem::create([
                            'menu_item_id' => $menuItem->id,
                            'ingredient_id' => $ingredients[$ingredientName]->id,
                            'qty' => $qty,
                        ]);
                    }
                }
            }
        }
    }

    private function seedLaundry(): void
    {
        foreach (self::LAUNDRY_ITEMS as $def) {
            LaundryItem::query()->firstOrCreate(
                ['name' => $def['name']],
                ['price' => $def['price'] * 100, 'active' => true],
            );
        }
    }

    private function seedVenues(): void
    {
        $branch = Branch::query()->active()->firstOrFail();

        foreach (self::VENUES as $def) {
            Venue::query()->firstOrCreate(
                ['name' => $def['name']],
                [
                    'max_capacity' => $def['max_capacity'],
                    'facilities' => $def['facilities'],
                    'hourly_rate' => $def['hourly'] * 100,
                    'half_day_rate' => $def['half_day'] * 100,
                    'full_day_rate' => $def['full_day'] * 100,
                    'active' => true,
                    'branch_id' => $branch->id,
                ],
            );
        }
    }
}
