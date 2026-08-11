<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class EarthquakeExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'min_magnitude' => ['nullable', 'numeric', 'min:0'],
            'max_magnitude' => ['nullable', 'numeric', 'min:0'],
            'min_depth' => ['nullable', 'numeric', 'min:0'],
            'max_depth' => ['nullable', 'numeric', 'min:0'],
            'center_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'center_lon' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:0'],
            'tsunami' => ['nullable', Rule::in(['all', 'yes', 'no'])],
            'place' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', Rule::in(['occurred', 'magnitude'])],
            'occurred_from' => ['required', 'date'],
            'occurred_to' => ['required', 'date', 'after_or_equal:occurred_from'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'min_magnitude' => $this->input('min_magnitude', config('seismo.default_filter_min_magnitude')),
            'tsunami' => $this->input('tsunami', 'all'),
            'sort' => $this->input('sort', 'occurred'),
        ]);
    }

    /**
     * @return array{
     *     min_magnitude: float,
     *     max_magnitude: float|null,
     *     min_depth: float|null,
     *     max_depth: float|null,
     *     center_lat: float|null,
     *     center_lon: float|null,
     *     radius_km: float|null,
     *     tsunami: string,
     *     place: string|null,
     *     sort: string,
     *     occurred_from: Carbon,
     *     occurred_to: Carbon
     * }
     */
    public function filters(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return [
            'min_magnitude' => (float) $validated['min_magnitude'],
            'max_magnitude' => isset($validated['max_magnitude']) ? (float) $validated['max_magnitude'] : null,
            'min_depth' => isset($validated['min_depth']) ? (float) $validated['min_depth'] : null,
            'max_depth' => isset($validated['max_depth']) ? (float) $validated['max_depth'] : null,
            'center_lat' => isset($validated['center_lat']) ? (float) $validated['center_lat'] : null,
            'center_lon' => isset($validated['center_lon']) ? (float) $validated['center_lon'] : null,
            'radius_km' => isset($validated['radius_km']) ? (float) $validated['radius_km'] : null,
            'tsunami' => (string) ($validated['tsunami'] ?? 'all'),
            'place' => isset($validated['place']) && $validated['place'] !== ''
                ? (string) $validated['place']
                : null,
            'sort' => (string) ($validated['sort'] ?? 'occurred'),
            'occurred_from' => Carbon::parse((string) $validated['occurred_from']),
            'occurred_to' => Carbon::parse((string) $validated['occurred_to']),
        ];
    }
}
