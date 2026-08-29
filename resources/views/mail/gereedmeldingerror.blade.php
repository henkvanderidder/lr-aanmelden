<!DOCTYPE html>
<html>
<head>
    <title>Aanmelding laptop, met Error</title>
</head>
<body>
    <h1>Beste Laptop Reviver,</h1>
    <p>Het is <b>niet gelukt</b> om je laptop automatisch gereed te melden.</p>
    <p>
    <table border="1" cellpadding="5" cellspacing="0">
        <tr>
            <th>Email CC</th>
            <td>{{ $laptop['cc'] ?? 'nvt' }}</td>
        </tr>
        <tr>
            <th>ERROR</th>
            <td>{{ $laptop['error'] ?? 'N/A' }}</td>
        </tr>
        <tr>
            <th>LR nummer</th>
            <td>{{ $laptop['lrnummer'] ?? 'N/A' }}</td>
        </tr>
        <tr>
            <th>Beperkingen</th>
            <td>{{ $laptop['beperkingen'] ?? 'nvt' }}</td>
        </tr>
        <tr>
            <th>Email</th>
            <td>{{ $laptop['email'] ?? 'N/A' }}</td>
        </tr>

      </table>
      <p>Probeer het nog een keer. Neem daarna contact op met de centrale administratie.</p>
      <p></p>
      <p>Met vriendelijke groet,<br>
      Het Laptop Revive Team</p>
</body>
</html>