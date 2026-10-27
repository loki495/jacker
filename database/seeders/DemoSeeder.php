<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Opportunity;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Fake listings for screenshots and demos. Run manually against a disposable database only:
 * php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Opportunity::query()->exists()) {
            throw new RuntimeException('DemoSeeder only runs on an empty database; point DB_DATABASE at a disposable file.');
        }

        $rows = [
            ['Northwind Labs', 'Senior Laravel Engineer', 'applied', 'remote', 'full_time', 140000, 170000, 5, 4],
            ['Contoso Health', 'PHP Backend Developer', 'interviewing', 'remote', 'full_time', 120000, 150000, 4, 4],
            ['Fabrikam Studio', 'Full-stack Developer (Livewire)', 'screening', 'hybrid', 'full_time', 110000, 135000, 4, 3],
            ['Tailspin Travel', 'Staff Engineer', 'offer', 'remote', 'full_time', 170000, 195000, 5, 5],
            ['Adventure Works', 'API Developer', 'preparing', 'remote', 'contract', 70, 90, 3, 3],
            ['Wingtip Toys', 'Junior PHP Developer', 'saved', 'on_site', 'full_time', 70000, 85000, 2, 2],
            ['Litware Inc', 'DevOps Engineer', 'saved', 'remote', 'full_time', 125000, 155000, 3, 4],
            ['Proseware', 'Laravel Contractor', 'contacted', 'remote', 'contract', 80, 100, 4, 3],
            ['Lucerne Publishing', 'Backend Engineer', 'rejected', 'hybrid', 'full_time', 100000, 120000, 3, 2],
            ['Coho Winery', 'Web Developer', 'withdrawn', 'on_site', 'part_time', 40, 55, 2, 1],
            ['Margie\'s Travel', 'Platform Engineer', 'closed', 'remote', 'full_time', 130000, 160000, 4, 4],
            ['Humongous Insurance', 'Senior PHP Architect', 'saved', 'remote', 'full_time', 150000, 180000, 5, 3],
        ];

        foreach ($rows as $i => [$company, $title, $status, $arrangement, $type, $min, $max, $skill, $want]) {
            $hourly = $min < 1000;
            Opportunity::query()->create([
                'company' => $company,
                'title' => $title,
                'status' => $status,
                'work_arrangement' => $arrangement,
                'employment_type' => $type,
                'currency' => 'USD',
                'pay_period' => $hourly ? 'hour' : 'year',
                'salary_min' => $min,
                'salary_max' => $max,
                'skill_match' => $skill,
                'desirability' => $want,
                'has_applied' => in_array($status, ['applied', 'screening', 'interviewing', 'offer', 'rejected'], true),
                'application_url' => 'https://example.com/jobs/'.($i + 1),
                'source' => 'Demo data',
                'location' => $arrangement === 'on_site' ? 'Springfield' : 'Worldwide',
            ]);
        }
    }
}
