package embed

import "encoding/json"

func jsonMarshalNoCheck(p Payload) ([]byte, error) { return json.Marshal(p) }
