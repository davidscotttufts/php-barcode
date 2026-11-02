<?php

declare(strict_types=1);

namespace PhpBarcode\Encoding;

/**
 * Systematic Reed–Solomon encoder over a binary Galois field.
 */
final class ReedSolomon
{
    /** @var array<int, list<int>> Generator polynomials by degree, highest coefficient first. */
    private array $generators = [];

    public function __construct(
        private readonly GaloisField $field,
    ) {}

    /**
     * Computes $count error-correction codewords for $data.
     *
     * @param list<int> $data
     *
     * @return list<int>
     */
    public function encode(array $data, int $count): array
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('At least one error-correction codeword is required.');
        }

        $generator = $this->generator($count);
        $remainder = array_fill(0, $count, 0);

        foreach ($data as $codeword) {
            $factor = $codeword ^ array_shift($remainder);
            $remainder[] = 0;
            if ($factor !== 0) {
                for ($i = 0; $i < $count; $i++) {
                    $remainder[$i] ^= $this->field->multiply($generator[$i + 1], $factor);
                }
            }
        }

        return array_values($remainder);
    }

    /**
     * ∏ (x − α^(base+i)) for i in [0, degree), as coefficients with the leading 1 first.
     *
     * @return list<int>
     */
    private function generator(int $degree): array
    {
        if (isset($this->generators[$degree])) {
            return $this->generators[$degree];
        }

        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $root = $this->field->exp($this->field->generatorBase + $i);
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $j => $coefficient) {
                $next[$j] ^= $coefficient;
                $next[$j + 1] ^= $this->field->multiply($coefficient, $root);
            }
            $poly = $next;
        }

        return $this->generators[$degree] = array_values($poly);
    }
}
