{{-- Any 4xx without its own view (405, 408, 409, 410, 422...): without this Symfony's default page is sent, whose inline <style> has no CSP nonce and is blocked under `enforce`.
     Only the standard status text is shown: the exception message of a routing error names routes and methods. --}}
@php($text = \Symfony\Component\HttpFoundation\Response::$statusTexts[$exception->getStatusCode()] ?? 'Error')
@extends('errors::minimal')

@section('title', __($text))
@section('code', (string) $exception->getStatusCode())
@section('message', __($text))
