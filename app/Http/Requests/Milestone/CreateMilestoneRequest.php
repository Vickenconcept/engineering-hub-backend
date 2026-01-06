<?php

namespace App\Http\Requests\Milestone;

use Illuminate\Foundation\Http\FormRequest;

class CreateMilestoneRequest extends FormRequest
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
        return [
            'milestones' => ['required', 'array', 'min:1'],
            'milestones.*.title' => ['required', 'string', 'max:255'],
            'milestones.*.description' => ['nullable', 'string', 'max:2000'],
            'milestones.*.amount' => ['required', 'numeric', 'min:0'],
            'milestones.*.sequence_order' => ['required', 'integer', 'min:1'],
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
            'milestones.required' => 'At least one milestone is required.',
            'milestones.min' => 'At least one milestone is required.',
            'milestones.*.title.required' => 'Milestone title is required.',
            'milestones.*.title.max' => 'Milestone title cannot exceed 255 characters.',
            'milestones.*.description.max' => 'Milestone description cannot exceed 2000 characters.',
            'milestones.*.amount.required' => 'Milestone amount is required.',
            'milestones.*.amount.numeric' => 'Milestone amount must be a valid number.',
            'milestones.*.amount.min' => 'Milestone amount cannot be negative.',
            'milestones.*.sequence_order.required' => 'Sequence order is required.',
            'milestones.*.sequence_order.integer' => 'Sequence order must be a whole number.',
            'milestones.*.sequence_order.min' => 'Sequence order must be at least 1.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $sequenceOrders = collect($this->input('milestones', []))->pluck('sequence_order');
            
            if ($sequenceOrders->unique()->count() !== $sequenceOrders->count()) {
                $validator->errors()->add('milestones', 'Sequence orders must be unique.');
            }
        });
    }
}
