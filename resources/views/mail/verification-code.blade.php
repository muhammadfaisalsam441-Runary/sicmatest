<x-mail::message>
# Verifikasi email SICMA

Gunakan kode berikut untuk memverifikasi email pendaftaran Keti:

<x-mail::panel>
**{{ $code }}**
</x-mail::panel>

Kode berlaku {{ $validMinutes }} menit. Abaikan email ini jika kamu tidak mendaftar di SICMA.

Salam,<br>
SICMA • JTIK UNM
</x-mail::message>
