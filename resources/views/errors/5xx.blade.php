{{-- Any 5xx without its own view (500, 503 have one; 501, 502, 504...): same reason as 4xx.blade.php. The message of a server error is never shown. --}}
@extends('errors::minimal')

@section('title', __('Server Error'))
@section('code', (string) $exception->getStatusCode())
@section('message', __('Server Error'))
