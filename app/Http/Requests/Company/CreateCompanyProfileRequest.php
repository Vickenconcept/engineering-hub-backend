<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class CreateCompanyProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isCompany() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = $this->user()?->company?->id;

        return [
            'company_name' => ['required', 'string', 'max:255'],
            'registration_number' => [
                'required',
                'string',
                'unique:companies,registration_number' . ($companyId ? ",{$companyId}" : ''),
            ],
            'license_documents' => ['nullable', 'array'],
            'license_documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'], // 5MB max
            'portfolio_links' => ['nullable', 'array'],
            'portfolio_links.*' => ['url', 'max:500'],
            'specialization' => ['nullable', 'array'],
            'specialization.*' => ['string', 'max:100'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_name.required' => 'Company name is required.',
            'company_name.max' => 'Company name cannot exceed 255 characters.',
            'registration_number.required' => 'Registration number is required.',
            'registration_number.unique' => 'This registration number is already registered.',
            'license_documents.*.file' => 'Each license document must be a valid file.',
            'license_documents.*.mimes' => 'License documents must be PDF, JPG, JPEG, or PNG files.',
            'license_documents.*.max' => 'Each license document cannot exceed 5MB.',
            'portfolio_links.*.url' => 'Each portfolio link must be a valid URL.',
            'portfolio_links.*.max' => 'Portfolio links cannot exceed 500 characters.',
            'specialization.*.max' => 'Each specialization cannot exceed 100 characters.',
        ];
    }
}
