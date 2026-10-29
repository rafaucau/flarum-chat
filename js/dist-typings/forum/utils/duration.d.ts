/**
 * Duração em unidades inteiras ("30s", "5m", "1h"), para caber numa frase e ao
 * lado do nome do canal. O slow mode só usa valores redondos, então não existe
 * caso "1m 30s".
 */
export declare function humanDuration(seconds: number): string;
