<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['sometimes', 'integer', Rule::in([1])],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.in' => 'El usuario de la venta debe ser el usuario estático con ID 1.',
            'items.required' => 'La venta debe contener al menos un producto.',
            'items.array' => 'Los productos de la venta deben enviarse como una lista.',
            'items.min' => 'La venta debe contener al menos un producto.',
            'items.*.product_id.required' => 'Cada producto de la venta es obligatorio.',
            'items.*.product_id.integer' => 'El ID del producto debe ser un entero.',
            'items.*.product_id.exists' => 'El producto seleccionado no existe.',
            'items.*.quantity.required' => 'La cantidad de cada producto es obligatoria.',
            'items.*.quantity.integer' => 'La cantidad debe ser un número entero.',
            'items.*.quantity.min' => 'La cantidad debe ser mayor que cero.',
            'items.*.quantity.max' => 'La cantidad supera el máximo permitido.',
        ];
    }
}
