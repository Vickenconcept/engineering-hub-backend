<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get company users
        $company1 = User::where('email', 'company@example.com')->first();
        $company2 = User::where('email', 'elite@example.com')->first();

        if ($company1) {
            Company::create([
                'user_id' => $company1->id,
                'company_name' => 'Building Masters Ltd',
                'registration_number' => 'RC123456',
                'license_documents' => null,
                'portfolio_links' => [
                    'https://example.com/portfolio/building-masters',
                    'https://example.com/projects/building-masters',
                ],
                'specialization' => [
                    'Residential Construction',
                    'Commercial Buildings',
                    'Architectural Design',
                ],
                'verified_at' => now(),
                'status' => Company::STATUS_APPROVED,
            ]);
        }

        if ($company2) {
            Company::create([
                'user_id' => $company2->id,
                'company_name' => 'Elite Construction',
                'registration_number' => 'RC789012',
                'license_documents' => null,
                'portfolio_links' => [
                    'https://example.com/portfolio/elite',
                ],
                'specialization' => [
                    'Luxury Homes',
                    'Interior Design',
                ],
                'verified_at' => null,
                'status' => Company::STATUS_PENDING,
            ]);
        }
    }
}
