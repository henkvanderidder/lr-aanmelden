<!DOCTYPE html>
<html>
<head>
    <title>Aanmelding laptop, met Error</title>
</head>
<body>
    <h1>Beste Laptop Reviver,</h1>
    <p>Het is <b>niet gelukt</b> om je laptop automatisch aan te melden.</p>
    <p>
    <table border="1" cellpadding="5" cellspacing="0">
        <tr>
            <th>Email CC</th>
            <td>{{ $laptop['cc'] ?? 'N/A' }}</td>
        </tr>
        <tr>
            <th>ERROR</th>
            <td>{{ $laptop['error'] ?? 'N/A' }}</td>
        </tr>
        <tr>
            <th>Merk</th>
            <td>{{ $laptop['manufacturer'] ?? 'N/A' }}</td>
        </tr>
        <tr>
            <th>Model</th>
            <td>{{ $laptop['productname'] ?? 'N/A' }}</td>
        </tr>
        <tr>
            <th>Serienummer</th>
            <td>{{ $laptop['serialnumber'] ?? 'N/A' }}</td>
        </tr>
        <tr>
            <th>Naam</th>
            <td>{{ $laptop['naam'] ?? 'N/A' }}</td>
        </tr>
        <tr>
            <th>Woonplaats</th>
            <td>{{ $laptop['woonplaats'] ?? 'N/A' }}</td>
        </tr>
        <tr>
            <th>Email</th>
            <td>{{ $laptop['email'] ?? 'N/A' }}</td>
        </tr>
      </table>
      <p>Er is een CC van dit bericht verstuurd aan de Central Administratie van Laptop Revive. 
         Zij zullen met u contact opnemen.</p>
      <p></p>
      <p>Met vriendelijke groet,<br>
      Het Laptop Revive Team</p>
</body>
</html>