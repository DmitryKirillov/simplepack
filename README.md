# Simplepack

This is an experimental PHP library that helps reduce the memory footprint when creating and using
small-sized associative arrays in large quantities.

Important note: This library is not production-ready yet!

## General information

This library was inspired by various algorithms used in databases and other languages (e.g. Redis).
The idea is to convert small hash tables to linear data structures and store them inside a single string.
This approach results in reduced memory consumption, not to mention cache locality. Obviously, 
the time complexity increases to O(n), but given the small size of the dataset the practical difference
is vanishingly small.

### Advantages

- Plain PHP code
- Supports PHP 5.4 and later
- Consumes ~4 times less memory than associative arrays
- Consumes ~2 times less memory than objects (DTOs)

### Disadvantages

- Works substantially slower than arrays and objects
- Cannot be used instead of arrays in all scenarios (see examples below)

### Use cases

- A lot of 1-dimensional arrays with dynamic string keys and rather small values
- A lot of foreach loops over these arrays
- Minimum update/delete operations

## Requirements

- PHP 5.4 or newer (not tested yet with PHP 5!)
- JSON extension (for older PHP versions)

## Installation

```sh
composer require dmitry-kirillov/simplepack
```

## Usage

Objects of this class can be used instead of associative arrays in most scenarios:

```php
$data = new Simplepack();
$data['name'] = 'John';
$data['age'] = 30;
$data['is_married'] = true;

foreach ($data as $key => $value) {
    // Your code here...
}
```

However, it is important to remember that certain functions and operators won't work:

```php
$keyExists = array_key_exists('name', $data); // PHP Fatal error
$data['age']++; // PHP Notice; the value stays the same
```

## Known Issues

- Extremely high CPU usage
- No support for 32-bit systems

## License

MIT