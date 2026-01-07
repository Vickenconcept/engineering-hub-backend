<?php

namespace App\Http\Requests\Consultation;

use Illuminate\Foundation\Http\FormRequest;

class CreateConsultationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isClient() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'exists:companies,id'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:120'],
            // Price is no longer accepted from client - backend uses company's consultation_fee
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
            'company_id.required' => 'Please select a company for the consultation.',
            'company_id.exists' => 'The selected company does not exist.',
            'scheduled_at.required' => 'Please select a date and time for the consultation.',
            'scheduled_at.after' => 'The consultation must be scheduled for a future date.',
            'duration_minutes.min' => 'Consultation duration must be at least 15 minutes.',
            'duration_minutes.max' => 'Consultation duration cannot exceed 120 minutes.',
            'price.required' => 'Please specify the consultation price.',
            'price.numeric' => 'Price must be a valid number.',
            'price.min' => 'Price cannot be negative.',
        ];
    }
}
