CA certificates for encrypted database connections (public files, not secrets).

`aiven-ca.pem` is the CA certificate of the Aiven project that hosts the online MySQL database
(Aiven console -> MySQL service -> Overview -> CA certificate -> Download). It is used through
`DB_SSL_CA=certs/aiven-ca.pem`. A local XAMPP/Laragon MySQL does not need it.
