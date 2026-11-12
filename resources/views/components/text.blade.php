@props(['signer', 'name', 'optional' => false])
{[{{ filter_var($optional, FILTER_VALIDATE_BOOL) ? '?' : '' }}text:{{ $signer }}:{{ $name }}]}
