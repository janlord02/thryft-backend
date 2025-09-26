<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Beauty & Wellness',
                'slug' => 'beauty-wellness',
                'description' => 'Beauty products, skincare, cosmetics, and wellness items',
                'icon' => 'spa',
                'color' => '#E91E63', // Pink
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Food & Beverage',
                'slug' => 'food-beverage',
                'description' => 'Food items, drinks, and culinary products',
                'icon' => 'restaurant',
                'color' => '#4CAF50', // Green
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Fashion & Apparel',
                'slug' => 'fashion-apparel',
                'description' => 'Clothing, accessories, and fashion items',
                'icon' => 'checkroom',
                'color' => '#9C27B0', // Purple
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'Electronics & Technology',
                'slug' => 'electronics-technology',
                'description' => 'Electronic devices, gadgets, and tech products',
                'icon' => 'devices',
                'color' => '#2196F3', // Blue
                'is_active' => true,
                'sort_order' => 4,
            ],
            [
                'name' => 'Home & Garden',
                'slug' => 'home-garden',
                'description' => 'Home decor, furniture, and garden supplies',
                'icon' => 'home',
                'color' => '#8BC34A', // Light Green
                'is_active' => true,
                'sort_order' => 5,
            ],
            [
                'name' => 'Sports & Fitness',
                'slug' => 'sports-fitness',
                'description' => 'Sports equipment, fitness gear, and athletic wear',
                'icon' => 'sports',
                'color' => '#FF9800', // Orange
                'is_active' => true,
                'sort_order' => 6,
            ],
            [
                'name' => 'Books & Media',
                'slug' => 'books-media',
                'description' => 'Books, magazines, movies, and music',
                'icon' => 'book',
                'color' => '#795548', // Brown
                'is_active' => true,
                'sort_order' => 7,
            ],
            [
                'name' => 'Health & Medical',
                'slug' => 'health-medical',
                'description' => 'Health products, medical supplies, and wellness items',
                'icon' => 'local_hospital',
                'color' => '#F44336', // Red
                'is_active' => true,
                'sort_order' => 8,
            ],
            [
                'name' => 'Travel & Tourism',
                'slug' => 'travel-tourism',
                'description' => 'Travel accessories, luggage, and tourism services',
                'icon' => 'flight',
                'color' => '#00BCD4', // Cyan
                'is_active' => true,
                'sort_order' => 9,
            ],
            [
                'name' => 'Pets & Animals',
                'slug' => 'pets-animals',
                'description' => 'Pet supplies, animal care products, and accessories',
                'icon' => 'pets',
                'color' => '#8BC34A', // Light Green
                'is_active' => true,
                'sort_order' => 10,
            ],
        ];

        foreach ($categories as $categoryData) {
            Category::create($categoryData);
        }
    }
}
