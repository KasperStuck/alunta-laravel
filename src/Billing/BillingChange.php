<?php

namespace KasperStuck\Alunta\Laravel\Billing;

use InvalidArgumentException;
use LogicException;

/**
 * The billing fields one apply writes. A field that is not set stays as it is, which is different
 * from setting it to null.
 */
final readonly class BillingChange
{
    /**
     * @param  array<string, mixed>  $values  keyed by BillingField value
     */
    private function __construct(private array $values) {}

    public static function make(): self
    {
        return new self([]);
    }

    /**
     * A copy of the change that also sets the field.
     *
     * @throws InvalidArgumentException when the field does not take the value
     */
    public function with(BillingField $field, mixed $value): self
    {
        if (! $field->accepts($value)) {
            throw new InvalidArgumentException("The billing field {$field->value} does not take a value of type ".get_debug_type($value).'.');
        }

        return new self([...$this->values, $field->value => $value]);
    }

    public function has(BillingField $field): bool
    {
        return array_key_exists($field->value, $this->values);
    }

    /**
     * The value the change sets for the field.
     *
     * @throws LogicException when the change does not set the field
     */
    public function value(BillingField $field): mixed
    {
        if (! $this->has($field)) {
            throw new LogicException("The billing change does not set {$field->value}.");
        }

        return $this->values[$field->value];
    }

    /**
     * The fields the change sets, in the order they were first set.
     *
     * @return list<BillingField>
     */
    public function fields(): array
    {
        return array_map(BillingField::from(...), array_keys($this->values));
    }
}
