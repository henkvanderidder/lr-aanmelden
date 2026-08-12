<!DOCTYPE html>
<html>
<head>
    <title>Aanmelding laptop</title>
</head>
<body>
    <h1>Beste Laptop Reviver,</h1>
    <p>Hieronder vind je de details van je laptop-aanmelding:</p>
    <table border="1" cellpadding="5" cellspacing="0">
        <tr>
            <th>LR nummer</th>
            <td>{{ $laptop['lrnummer'] ?? 'N/A' }}</td>
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
      </table>
      <p>Succes met het installeren van de laptop.</p>
      <p>Zodra de laptop geïnstalleerd is, kun je hem gereedmelden via het formulier: Laptop Gereedmelden. 
         Dan wordt de laptop 'Op Voorraad' gezet.</p>
      <p></p>
      <p>Met vriendelijke groet,<br>
      Het Laptop Revive Team</p>
</body>
</html>