<?php declare(strict_types = 1);

/**
 * Manual PHP engine diagnostic — property vs ArrayAccess compound assignment on a toy Box class.
 *
 * Not a contract gate. Intentionally emits warnings.
 *
 * Run manually: php tests/ref_ops.php
 */

class Box implements \ArrayAccess
{
    private array $scratch = [];

    private array $d = ['a' => 1, 's' => 'hi'];

    public function &__get(string $k): mixed
    {
        $this->scratch[$k] = $this->d[$k];

        return $this->scratch[$k];
    }

    public function __set(string $k, mixed $v): void
    {
        $this->d[$k] = $v;
        unset($this->scratch[$k]);
    }

    public function offsetExists(mixed $o): bool
    {
        return isset($this->d[$o]);
    }

    public function offsetGet(mixed $o): mixed
    {
        return $this->__get($o);
    }

    public function offsetSet(mixed $o, mixed $v): void
    {
        $this->__set($o, $v);
    }

    public function offsetUnset(mixed $o): void
    {
        unset($this->d[$o]);
    }
}

$b = new Box();
$b->a++;
echo 'prop ++: ' . $b->d['a'] . PHP_EOL;
$b->a += 5;
echo 'prop +=: ' . $b->d['a'] . PHP_EOL;
$b->s .= '!';
echo 'prop .=: ' . $b->d['s'] . PHP_EOL;
$b->a--;
echo 'prop --: ' . $b->d['a'] . PHP_EOL;

$b['a']++;
echo 'offset ++ (broken expected): ' . $b->d['a'] . PHP_EOL;
