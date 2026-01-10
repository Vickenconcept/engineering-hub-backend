<?php

namespace App\Http\Resources;

use App\Helpers\MoneyFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsultationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'company_id' => $this->company_id,
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'duration_minutes' => $this->duration_minutes,
            'price' => MoneyFormatter::format($this->price),
            'payment_status' => $this->payment_status,
            'meeting_link' => $this->meeting_link,
            'status' => $this->status,
            'is_paid' => $this->isPaid(),
            'is_completed' => $this->isCompleted(),
            'client' => $this->whenLoaded('client', fn () => new UserResource($this->client)),
            'company' => $this->whenLoaded('company', fn () => new CompanyResource($this->company)),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
