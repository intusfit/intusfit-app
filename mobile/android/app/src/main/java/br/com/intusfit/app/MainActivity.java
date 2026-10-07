package br.com.intusfit.app;

import android.content.pm.ActivityInfo;
import android.os.Bundle;
import android.webkit.JavascriptInterface;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        // O app fica sempre em retrato (AndroidManifest). Só o vídeo em tela cheia pode girar: a página avisa por
        // aqui quando entra e quando sai da tela cheia (ver o script de orientação no aluno.html).
        getBridge().getWebView().addJavascriptInterface(new OrientacaoJs(), "IntusNativo");
    }

    private class OrientacaoJs {
        // livre = true: gira conforme a configuração de giro automático do aparelho; false: volta ao retrato.
        @JavascriptInterface
        public void telaCheia(final boolean livre) {
            runOnUiThread(new Runnable() {
                @Override
                public void run() {
                    setRequestedOrientation(livre ? ActivityInfo.SCREEN_ORIENTATION_USER : ActivityInfo.SCREEN_ORIENTATION_PORTRAIT);
                }
            });
        }
    }
}
