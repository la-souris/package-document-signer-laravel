@props(['signer', 'name', 'optional' => false])
{[{{ filter_var($optional, FILTER_VALIDATE_BOOL) ? '?' : '' }}initials:{{ $signer }}:{{ $name }}]}
