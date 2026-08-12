<!DOCTYPE html>
<html>
<head>
    <title>Aanmelding laptop</title>
</head>
<body>
    <h1>Beste Laptop Reviver,</h1>
    <p>Hieronder vind je de details van je laptop gereedmelding:</p>
    <table border="1" cellpadding="5" cellspacing="0">
        <tr>
            <th>LR nummer</th>
            <td>{{ $laptop['lrnummer'] ?? 'N/A' }} Op Voorraad</td>
        </tr>
        <tr>
            <th>Email</th>
            <td>{{ $laptop['email'] ?? 'N/A' }}</td>
        </tr>
        <tr>
            <th>Naam</th>
            <td>{{ $laptop['naam'] ?? 'N/A' }}</td>
        </tr>
      </table>
      <p></p>
      <p>Met vriendelijke groet,<br>
      Het Laptop Revive Team</p>
</body>
</html>