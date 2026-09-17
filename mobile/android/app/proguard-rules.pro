# SQLCipher : ses classes sont appelées depuis le code natif. Le réducteur de code
# de la version de production les supprimerait, et la base chiffrée ne s'ouvrirait
# plus (documentation de sqflite_sqlcipher).
-keep class net.sqlcipher.** { *; }
