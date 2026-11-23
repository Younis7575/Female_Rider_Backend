<!doctype html>
<html>
<body style="margin:0;padding:24px;background:#f6f3f5;font-family:Arial,Helvetica,sans-serif;color:#1f1a1d">
  <table role="presentation" width="100%" style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:12px;padding:28px">
    <tr><td>
      <h2 style="margin:0 0 8px;color:#b0124f">FemaleRider</h2>
      <p style="margin:0 0 16px">
        @if($purpose === 'register') Use this code to verify your email and finish creating your account.
        @elseif($purpose === 'reset') Use this code to reset your password.
        @else Use this code to log in to your account.
        @endif
      </p>
      <p style="font-size:32px;letter-spacing:8px;font-weight:bold;margin:0 0 16px">{{ $code }}</p>
      <p style="margin:0 0 8px;color:#6b6168">The code expires in {{ $minutes }} minutes.</p>
      <p style="margin:0;color:#6b6168">Never share this code with anyone. If you did not request it, you can ignore this email.</p>
    </td></tr>
  </table>
</body>
</html>
